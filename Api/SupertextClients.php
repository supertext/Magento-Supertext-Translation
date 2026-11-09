<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Api;

use Supertext\Sdk\V1\Client;

/**
 * The SDK clients for translations: the direct one, and for store views with *Translation review*
 * on a second one with the same key, timeout, transport and User-Agent whose base URL is the review
 * service (created on first use). Each translation is a full round trip (upload, status, download,
 * delete) with one client, so a job is always collected with the client that submitted it. The key
 * check, version reports and translation files always use the direct API (Config::baseUrl()).
 *
 * No Magento dependencies.
 */
final class SupertextClients
{
    private ?Client $direct = null;

    private ?Client $review = null;

    private readonly ?string $reviewUrl;

    /**
     * @param \Closure(string): Client $make builds a client for a base URL
     * @param string                   $reviewUrl the review service ('' = review off for every store view)
     */
    public function __construct(
        private readonly \Closure $make,
        private readonly string $baseUrl,
        string $reviewUrl,
    ) {
        $this->reviewUrl = Supertext::reviewBaseUrl($reviewUrl, $baseUrl);
    }

    /** The client for a store view: the review service when its *Translation review* is on, else the direct one. */
    public function forReview(bool $review): Client
    {
        if ($review && $this->reviewUrl !== null) {
            return $this->review ??= ($this->make)($this->reviewUrl);
        }

        return $this->direct ??= ($this->make)($this->baseUrl);
    }

    /** Whether translations of store views with *Translation review* on go to the review service ('' review URL: no). */
    public function reviewAvailable(): bool
    {
        return $this->reviewUrl !== null;
    }
}
