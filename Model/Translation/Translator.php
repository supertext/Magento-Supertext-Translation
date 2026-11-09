<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Model\Translation;

use Supertext\Sdk\V1\SupertextException as SdkException;
use Supertext\Translation\Api\Supertext;
use Supertext\Translation\Api\SupertextClients;
use Supertext\Translation\Api\SupertextException;
use Supertext\Translation\Model\Config;
use Supertext\Translation\Model\Reporting;

/** One entry point for the translate page, its endpoint and the console command. */
class Translator
{
    private ?SupertextClients $clients = null;

    public function __construct(
        private readonly Config $config,
        private readonly EavTranslator $eav,
        private readonly CmsTranslator $cms,
        private readonly Reporting $reporting,
    ) {
    }

    public function exists(string $type, int $id): bool
    {
        return $this->handler($type)->exists($type, $id);
    }

    public function title(string $type, int $id): string
    {
        return $this->handler($type)->title($type, $id);
    }

    /** @param list<int> $storeIds @return array<int, string> */
    public function states(string $type, int $id, array $storeIds): array
    {
        return $this->handler($type)->states($type, $id, $storeIds);
    }

    /**
     * @return array{status: string, title: string, translated: list<string>, kept: list<string>, notes: list<string>, targetId?: int}
     *               status: "translated", "created" (new CMS copy) or "unchanged"
     *
     * @throws SupertextException|\Magento\Framework\Exception\LocalizedException
     */
    public function translate(string $type, int $id, int $storeId, bool $overwrite): array
    {
        if ($this->config->apiKey() === '') {
            throw new SupertextException(
                'Supertext is not set up yet: an administrator needs to enter the API key in Stores → Configuration → Supertext → Translation. No Supertext account yet? Create one at %1. Generate your API key at %2 (requires the Admin role).',
                [Config::SIGNUP_URL, Config::API_KEY_URL],
            );
        }

        if ($storeId <= 0) {
            throw new SupertextException('Choose a store view to translate into.');
        }

        // Both codes first: a store view without a Supertext language fails here, before anything is sent.
        $target = $this->config->targetCode($storeId);
        $source = $this->config->sourceCode();

        // Store views with *Translation review* on go through the review service (same key, same protocol).
        $this->clients ??= $this->config->clients();
        $client = $this->clients->forReview($this->config->review($storeId));

        try {
            return $this->handler($type)->translate($client, $type, $id, $storeId, $overwrite);
        } catch (SdkException $e) {
            if ($e->errorCode === SdkException::AUTHENTICATION_FAILURE) {
                $this->reporting->authenticationFailed((int) ($e->status ?? 401));
            }

            throw Supertext::error($e, $source, $target);
        }
    }

    /**
     * For a translation file: the texts translate() would send (field => text, rich text with its
     * directives protected) and the fields that keep their translation; null if the item doesn't exist.
     *
     * @return array{segments: array<string, array{text: string, html: bool}>, kept: list<string>}|null
     */
    public function fileSegments(string $type, int $id, int $storeId, bool $overwrite): ?array
    {
        if (!$this->exists($type, $id)) {
            return null;
        }

        $planner  = $this->handler($type)->planner($type, $id, $storeId, $overwrite);
        $segments = [];

        foreach ($planner->segments() as $field => $segment) {
            $segments[$field] = ['text' => $segment->text, 'html' => $segment->html];
        }

        return ['segments' => $segments, 'kept' => $planner->kept()];
    }

    /**
     * Writes the texts of a translation file (field => text, as fileSegments() gave them) exactly like
     * a translation with Supertext, without calling Supertext.
     *
     * @param array<string, string> $texts
     *
     * @return array{status: string, title: string, translated: list<string>, kept: list<string>, notes: list<string>, targetId?: int}
     *
     * @throws SupertextException|\Magento\Framework\Exception\LocalizedException
     */
    public function importTexts(string $type, int $id, int $storeId, bool $overwrite, array $texts): array
    {
        if ($storeId <= 0) {
            throw new SupertextException('Choose a store view to translate into.');
        }

        return $this->handler($type)->translate(static fn (array $segments): array => array_intersect_key($texts, $segments), $type, $id, $storeId, $overwrite);
    }

    private function handler(string $type): EavTranslator|CmsTranslator
    {
        return EntityTypes::get($type)['kind'] === 'cms' ? $this->cms : $this->eav;
    }
}
