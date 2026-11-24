<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Model;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Escaper;
use Magento\Framework\FlagManager;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\PackageInfo;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Supertext\PluginHealth\KeyCheck;
use Supertext\PluginHealth\PingResponse;
use Supertext\PluginHealth\ReporterResult;
use Supertext\Translation\Api\Reporting as Glue;

/**
 * Magento's side of the Commando Center (version reports, the automatic API key check, the ping):
 * the shop's values for Api\Reporting and its state in Magento's flag table (FlagManager, code
 * `supertext_reporter`). Nothing here throws into a save, a page or a translation.
 */
class Reporting
{
    public const FLAG = 'supertext_reporter';

    private bool $scheduled = false;

    public function __construct(
        private readonly Config $config,
        private readonly FlagManager $flags,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly TimezoneInterface $timezone,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger,
        private readonly FullModuleList $fullModuleList,
        private readonly ModuleListInterface $moduleList,
        private readonly PackageInfo $packageInfo,
    ) {
    }

    /** Whether reports are on (the setting; SUPERTEXT_VERSION_REPORTS wins). */
    public function enabled(): bool
    {
        return Glue::enabled($this->config->versionReportsSetting());
    }

    public function forcedByEnvironment(): bool
    {
        return Glue::forcedByEnvironment();
    }

    /** After a save of Stores → Configuration → Supertext → Translation: checks the key, reports. */
    public function afterSettingsSaved(): ?ReporterResult
    {
        return $this->glue()?->afterSettingsSaved($this->config->apiKey());
    }

    /** *Test connection*: checks the key, reports a change. */
    public function testConnection(): ?ReporterResult
    {
        return $this->glue()?->testConnection($this->config->apiKey());
    }

    /** A translation got 401/403. In a web request after the response, on the command line right away. */
    public function authenticationFailed(int $httpStatus = 401): void
    {
        $this->later(fn () => $this->glue()?->authenticationFailed($this->config->apiKey(), $httpStatus));
    }

    /**
     * Admin pages and actions of the module (settings, translate page, a translation, the command):
     * checks a changed key and reports an update. Under PHP-FPM after the response has been sent;
     * at most once per request; makes a request only when something is due.
     */
    public function onRequest(): void
    {
        if ($this->scheduled) {
            return;
        }

        $this->scheduled = true;
        $this->later(fn () => $this->glue()?->onRequest($this->config->apiKey()));
    }

    /**
     * "Send configuration to Supertext": the installed modules (not Magento's or Mage-OS's own) and
     * every store view's locale with its Supertext language, in one report.
     *
     * @return array{sent: bool, extensions: int, languages: int}|null null with reports off
     */
    public function sendConfiguration(): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $extensions = $this->installedModules();
        $languages  = [];

        foreach ($this->config->storeViews() as $store) {
            $languages[] = ['code' => $store['code'] . ':' . $store['locale'], 'supertext' => $store['target']];
        }

        $result = $this->glue()?->sendConfiguration($extensions, $languages);

        return ['sent' => (bool) $result?->sent, 'extensions' => \count($extensions), 'languages' => \count($languages)];
    }

    /**
     * Installed modules except Magento's and Mage-OS's own: Composer package name and version where
     * there is one, enabled or not.
     *
     * @return list<array{name: string, version: ?string, active: bool, type: string}>
     */
    public function installedModules(): array
    {
        $out = [];

        try {
            foreach ($this->fullModuleList->getNames() as $module) {
                if (str_starts_with($module, 'Magento_') || str_starts_with($module, 'MageOS_')) {
                    continue;
                }

                $package = (string) $this->packageInfo->getPackageName($module);
                $version = (string) $this->packageInfo->getVersion($module);
                $out[]   = [
                    'name'    => $package !== '' ? $package : $module,
                    'version' => $version !== '' ? ltrim($version, 'v') : null,
                    'active'  => $this->moduleList->has($module),
                    'type'    => 'module',
                ];
            }
        } catch (\Throwable) {
            // A report without modules still has the languages.
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** The latest stored key check, for the settings page. */
    public function lastCheck(): ?KeyCheck
    {
        return $this->glue()?->lastCheck();
    }

    /** The answer of the public ping endpoint. */
    public function ping(?string $challenge): PingResponse
    {
        return Glue::ping($challenge, $this->enabled());
    }

    /** The ping endpoint's absolute URL, as reported to Supertext. */
    public function pingUrl(): ?string
    {
        return Glue::pingUrl($this->baseUrl(UrlInterface::URL_TYPE_LINK));
    }

    /**
     * A key check as a message for the admin: whether it's good, plain text (bare URLs) and HTML
     * (links in a new tab), in the admin user's language.
     *
     * @return array{ok: bool, status: string, text: string, html: string, time: string}
     */
    public function describe(KeyCheck $check): array
    {
        [$template, $arguments] = match ($check->status) {
            KeyCheck::OK          => ['API key checked: the connection to Supertext works.', []],
            KeyCheck::INVALID     => ['Supertext rejected the API key. Check that you copied the whole key, or generate a new one at %1 (requires the Admin role). No Supertext account yet? Create one at %2.', ['api', 'signup']],
            KeyCheck::MISSING     => ['No API key is set yet. No Supertext account yet? Create one at %1. Generate your API key at %2 (requires the Admin role).', ['signup', 'api']],
            KeyCheck::UNREACHABLE => ['The API key couldn\'t be checked because Supertext can\'t be reached. The settings are saved; check again later.', []],
            default                  => ['The API key couldn\'t be checked: Supertext answered with an error (%1). The settings are saved; check again later.', ['status']],
        };

        $status = $check->httpStatus !== null ? 'HTTP ' . $check->httpStatus : '';
        if ($check->errorCode !== null && $check->errorCode !== '') {
            $status = trim($status . ' ' . $check->errorCode);
        }

        $text  = [];
        $html  = [];
        $links = [
            'signup' => [Config::SIGNUP_URL, 'supertext.com'],
            'api'    => [Config::API_KEY_URL, 'supertext.com → Integrations → API'],
        ];

        foreach ($arguments as $i => $argument) {
            $token = '{{supertext-' . $i . '}}';

            if ($argument === 'status') {
                $text[] = $status !== '' ? $status : '–';
                $html[$token] = $this->escaper->escapeHtml($status !== '' ? $status : '–');
            } else {
                [$url, $label] = $links[$argument];
                $text[] = $url;
                $html[$token] = '<a href="' . $this->escaper->escapeUrl($url) . '" target="_blank" rel="noopener">' . $this->escaper->escapeHtml($label) . '</a>';
            }
        }

        return [
            'ok'     => $check->status === KeyCheck::OK,
            'status' => $check->status,
            'text'   => (string) __($template, ...$text),
            'html'   => strtr($this->escaper->escapeHtml((string) __($template, ...array_keys($html))), $html),
            'time'   => $this->formatTime($check->checkedAt),
        ];
    }

    private function formatTime(string $checkedAt): string
    {
        try {
            return (string) $this->timezone->formatDateTime(new \DateTime($checkedAt), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::MEDIUM);
        } catch (\Throwable) {
            return $checkedAt;
        }
    }

    private function glue(): ?Glue
    {
        try {
            return new Glue(
                enabled: $this->enabled(),
                siteUrl: $this->baseUrl(UrlInterface::URL_TYPE_WEB),
                pluginVersion: $this->config->version() !== '' ? $this->config->version() : 'dev',
                systemVersion: (string) $this->productMetadata->getVersion(),
                callbackUrl: $this->pingUrl(),
                apiBaseUrl: $this->config->baseUrl(),
                load: function (): ?string {
                    $data = $this->flags->getFlagData(self::FLAG);

                    return \is_string($data) ? $data : null;
                },
                save: function (string $json): void {
                    $this->flags->saveFlag(self::FLAG, $json);
                },
                systemName: (string) $this->productMetadata->getName() ?: Glue::SYSTEM_NAME,
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Supertext: version reporting unavailable: ' . $e->getMessage());

            return null;
        }
    }

    /** The default store view's secure base URL ("https://shop.example.ch/"), or null. */
    private function baseUrl(string $type): ?string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();

            if (!$store instanceof Store) {
                return null;
            }

            $url = (string) $store->getBaseUrl($type, $store->isFrontUrlSecure());

            return preg_match('#^https?://#i', $url) ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Runs a reporting action after the response (PHP-FPM) or at the end of the request; on the CLI now. */
    private function later(\Closure $action): void
    {
        $safe = function () use ($action): void {
            try {
                $action();
            } catch (\Throwable $e) {
                $this->logger->debug('Supertext: version reporting failed: ' . $e->getMessage());
            }
        };

        if (\PHP_SAPI === 'cli') {
            $safe();

            return;
        }

        register_shutdown_function(static function () use ($safe): void {
            if (\function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            $safe();
        });
    }
}
