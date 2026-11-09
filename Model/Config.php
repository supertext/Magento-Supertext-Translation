<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Module\PackageInfo;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Supertext\Sdk\V1\Client;
use Supertext\Sdk\V1\Politeness;
use Supertext\Translation\Api\LanguageCodes;
use Supertext\Translation\Api\Supertext;
use Supertext\Translation\Api\SupertextClients;
use Supertext\Translation\Api\SupertextException;

/**
 * The module's settings (Stores → Configuration → Supertext → Translation), with the
 * environment variables SUPERTEXT_API_KEY, SUPERTEXT_API_ENDPOINT and SUPERTEXT_REVIEW_URL taking precedence,
 * and the store views as translation targets.
 */
class Config
{
    public const PATH_API_KEY     = 'supertext/api/api_key';
    public const PATH_ENVIRONMENT = 'supertext/api/environment';
    public const PATH_ENDPOINT    = 'supertext/api/endpoint';
    public const PATH_TIMEOUT     = 'supertext/api/timeout';
    public const PATH_CODE        = 'supertext/language/code';
    public const PATH_TONE        = 'supertext/language/tone';
    public const PATH_REVIEW      = 'supertext/language/review';
    public const PATH_REVIEW_URL  = 'supertext/api/review_url';
    public const PATH_CMS_STATUS  = 'supertext/cms/status';
    public const PATH_REPORTS     = 'supertext/api/version_reports';

    public const SIGNUP_URL  = Supertext::SIGNUP_URL;
    public const API_KEY_URL = Supertext::API_KEY_URL;
    public const REPOSITORY  = 'https://github.com/Supertext/Magento-Supertext-Translation';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager,
        private readonly PackageInfo $packageInfo,
    ) {
    }

    public function apiKey(): string
    {
        $env = (string) getenv('SUPERTEXT_API_KEY');

        if (trim($env) !== '') {
            return Supertext::normalizeKey($env);
        }

        $stored = (string) $this->scopeConfig->getValue(self::PATH_API_KEY);

        return Supertext::normalizeKey($stored === '' ? '' : (string) $this->encryptor->decrypt($stored));
    }

    public function apiKeyFromEnvironment(): bool
    {
        return trim((string) getenv('SUPERTEXT_API_KEY')) !== '';
    }

    public function endpointFromEnvironment(): bool
    {
        return trim((string) getenv('SUPERTEXT_API_ENDPOINT')) !== '';
    }

    public function baseUrl(): string
    {
        $env = trim((string) getenv('SUPERTEXT_API_ENDPOINT'));

        return Supertext::baseUrlFor(
            (string) $this->scopeConfig->getValue(self::PATH_ENVIRONMENT),
            $env !== '' ? $env : (string) $this->scopeConfig->getValue(self::PATH_ENDPOINT),
        );
    }

    public function timeout(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::PATH_TIMEOUT);

        return $value >= 30 ? min($value, 1800) : 180;
    }

    /** A Supertext SDK client with the configured key, endpoint and timeout. */
    public function client(): Client
    {
        return Supertext::client($this->apiKey(), $this->baseUrl(), $this->timeout(), $this->version());
    }

    /**
     * The review service for store views with *Translation review* on: SUPERTEXT_REVIEW_URL when not
     * empty, else the setting (default Supertext's review service; saved empty: review off for every
     * store view).
     */
    public function reviewUrl(): string
    {
        $env = trim((string) getenv('SUPERTEXT_REVIEW_URL'));

        if ($env !== '') {
            return $env;
        }

        $value = $this->scopeConfig->getValue(self::PATH_REVIEW_URL);

        return $value === null ? Supertext::REVIEW_URL : trim((string) $value);
    }

    public function reviewUrlFromEnvironment(): bool
    {
        return trim((string) getenv('SUPERTEXT_REVIEW_URL')) !== '';
    }

    /** The store view's *Translation review* setting (default off). */
    public function review(int $storeId): bool
    {
        return (bool) (int) $this->scopeConfig->getValue(self::PATH_REVIEW, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The clients for translations: direct, and for store views with *Translation review* on the
     * review service (Api\SupertextClients::forReview()). Never for the key check, reports or files.
     */
    public function clients(): SupertextClients
    {
        $apiKey  = $this->apiKey();
        $timeout = $this->timeout();
        $version = $this->version();

        return new SupertextClients(
            static fn (string $baseUrl): Client => Supertext::client($apiKey, $baseUrl, $timeout, $version),
            $this->baseUrl(),
            $this->reviewUrl(),
        );
    }

    /** "same" (as the original) or "disabled" */
    public function cmsStatus(): string
    {
        return $this->scopeConfig->getValue(self::PATH_CMS_STATUS) === 'disabled' ? 'disabled' : 'same';
    }

    /** The store's locale, e.g. "de_CH" (store id 0: the default locale). */
    public function locale(int $storeId): string
    {
        return $storeId === 0
            ? (string) $this->scopeConfig->getValue('general/locale/code')
            : (string) $this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** The store view's *Supertext language* setting ("" if none). */
    public function languageSetting(int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue(self::PATH_CODE, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Sent to Supertext as target language: the store view's Supertext language, else its locale when
     * that equals a Supertext code ("de_CH" → "de-CH"), else the SDK's default for it ("es_ES" → "es",
     * "zh_Hans_CN" → "zh-Hans"). Locales without a default ("en_IE", "de_LI") need the setting.
     *
     * @throws SupertextException unsupported_language (nothing is sent)
     */
    public function targetCode(int $storeId): string
    {
        return LanguageCodes::target($this->locale($storeId), $this->languageSetting($storeId));
    }

    /** The target code, or null when the store view has neither a Supertext language nor a default. */
    public function targetCodeOrNull(int $storeId): ?string
    {
        return LanguageCodes::targetOrNull($this->locale($storeId), $this->languageSetting($storeId));
    }

    /**
     * Source language: the default locale's language ("en_US" → "en"). One setting serves both
     * directions: the default store view's Supertext language counts when it has the default locale.
     *
     * @throws SupertextException unsupported_language
     */
    public function sourceCode(): string
    {
        return LanguageCodes::source($this->locale(0), $this->sourceSetting());
    }

    public function sourceCodeOrNull(): ?string
    {
        return LanguageCodes::sourceOrNull($this->locale(0), $this->sourceSetting());
    }

    private function sourceSetting(): string
    {
        try {
            $default = (int) $this->storeManager->getDefaultStoreView()?->getId();
        } catch (\Throwable) {
            return '';
        }

        return $default > 0 && strcasecmp($this->locale($default), $this->locale(0)) === 0 ? $this->languageSetting($default) : '';
    }

    /** The configured tone ("more", "less" or "default"), whether or not Supertext offers it for the store view. */
    public function toneSetting(int $storeId): string
    {
        $tone = (string) $this->scopeConfig->getValue(self::PATH_TONE, ScopeInterface::SCOPE_STORE, $storeId);

        return \in_array($tone, [Politeness::MORE, Politeness::LESS], true) ? $tone : Politeness::DEFAULT;
    }

    /**
     * The tone sent to Supertext: formal/informal only where Supertext offers it for the target
     * (Languages::supportsPoliteness()); otherwise "default", so nothing is sent.
     */
    public function tone(int $storeId): string
    {
        $tone   = $this->toneSetting($storeId);
        $target = $this->targetCodeOrNull($storeId);

        if ($tone === Politeness::DEFAULT || $target === null || LanguageCodes::supportsPoliteness($target) === false) {
            return Politeness::DEFAULT;
        }

        return $tone;
    }

    /** The *Send version information to Supertext* setting (default on); SUPERTEXT_VERSION_REPORTS wins, see Api\Reporting::enabled(). */
    public function versionReportsSetting(): bool
    {
        $value = $this->scopeConfig->getValue(self::PATH_REPORTS);

        return $value === null || $value === '' || (bool) (int) $value;
    }

    /**
     * Store views that can be translated into, with their language. `target` is null for a store
     * view with neither a Supertext language nor a default for its locale (it can't be translated
     * into); `origin` says where the target comes from: "setting", "locale" (the locale is a
     * Supertext code) or "default" (the SDK's default for the locale).
     *
     * @return list<array{id: int, name: string, code: string, website: string, locale: string, setting: string, target: ?string, origin: string, active: bool, sameLanguage: bool, tone: string, review: bool, toneIgnored: bool}>
     */
    public function storeViews(): array
    {
        $source = $this->sourceCodeOrNull();
        $rows   = [];

        foreach ($this->storeManager->getStores(false) as $store) {
            $id     = (int) $store->getId();
            $target = $this->targetCodeOrNull($id);
            $tone   = $this->toneSetting($id);
            $rows[] = [
                'id'           => $id,
                'name'         => (string) $store->getName(),
                'code'         => (string) $store->getCode(),
                'website'      => (string) $this->storeManager->getWebsite($store->getWebsiteId())->getName(),
                'locale'       => $this->locale($id),
                'setting'      => $this->languageSetting($id),
                'target'       => $target,
                'origin'       => $this->languageSetting($id) !== '' ? 'setting' : (LanguageCodes::isExactMatch($this->locale($id)) ? 'locale' : 'default'),
                'active'       => (bool) $store->getIsActive(),
                'sameLanguage' => $target !== null && $source !== null && LanguageCodes::sourceOrNull($target) === $source,
                'tone'         => $tone,
                'review'       => $this->review($id),
                'toneIgnored'  => $tone !== Politeness::DEFAULT && $target !== null && LanguageCodes::supportsPoliteness($target) === false,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['website'], $a['id']] <=> [$b['website'], $b['id']]);

        return $rows;
    }

    public function version(): string
    {
        return (string) $this->packageInfo->getVersion('Supertext_Translation');
    }

    public function releaseUrl(): string
    {
        $version = $this->version();

        return preg_match('/^\d+\.\d+\.\d+$/', $version) ? self::REPOSITORY . '/releases/tag/v' . $version : '';
    }
}
