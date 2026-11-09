<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Model\Translation;

use Magento\Framework\AuthorizationInterface;
use Magento\Store\Model\StoreManagerInterface;
use Supertext\Translation\Model\Config;

/**
 * Items for translation files: the same texts and write-back as a translation with Supertext
 * (Translator), the admin user's ACL per content type.
 */
class TranslationFileItems implements FileItems
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly AuthorizationInterface $authorization,
    ) {
    }

    public function storeIds(): array
    {
        return array_values(array_filter(array_map(static fn ($store): int => (int) $store->getId(), $this->storeManager->getStores(false))));
    }

    public function sourceLanguage(): string
    {
        return $this->config->sourceCodeOrNull() ?? str_replace('_', '-', $this->config->locale(0));
    }

    public function targetLanguage(int $storeId): string
    {
        return $this->config->targetCodeOrNull($storeId) ?? str_replace('_', '-', $this->config->locale($storeId));
    }

    public function allowed(string $type): bool
    {
        return isset(EntityTypes::TYPES[$type]) && $this->authorization->isAllowed('Supertext_Translation::translate')
            && $this->authorization->isAllowed(EntityTypes::get($type)['acl']);
    }

    public function segments(string $type, int $id, int $storeId, bool $overwrite): ?array
    {
        return $this->translator->fileSegments($type, $id, $storeId, $overwrite);
    }

    public function write(string $type, int $id, int $storeId, bool $overwrite, array $translations): array
    {
        return $this->translator->importTexts($type, $id, $storeId, $overwrite, $translations)['notes'];
    }
}
