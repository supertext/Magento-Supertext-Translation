<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Api;

use Supertext\PluginHealth\CallableKeyChecker;
use Supertext\PluginHealth\InstallationReporter;
use Supertext\PluginHealth\KeyCheck;
use Supertext\PluginHealth\PingResponse;
use Supertext\PluginHealth\ReporterResult;
use Supertext\PluginHealth\ReporterState;
use Supertext\Sdk\Http\HttpTransport;
use Supertext\Sdk\Supertext;
use Supertext\Sdk\V1\Client;
use Supertext\Sdk\V1\Protocol;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check and the ping
 * (Commando-Center-Supertext-Translation → docs/DEVELOPER.md → Report protocol). The protocol is
 * Supertext Plugin Health's InstallationReporter (lib/supertext-plugin-health), the key check the
 * SDK's Client::checkApiKey(); this class only loads the reporter's state, runs one action and
 * stores the new state. Nothing here ever throws: reporting must never break a save or a translation.
 *
 * No Magento dependencies (Model\Reporting gives it the shop's values and storage).
 */
final class Reporting
{
    /** The module's GitHub repository name, as the center expects it. */
    public const PLUGIN_NAME = 'Magento-Supertext-Translation';

    public const SYSTEM_NAME = 'Magento';

    /** @var callable(): ?string */
    private $load;

    /** @var callable(string): void */
    private $save;

    /** @var (callable(): float)|null */
    private $now;

    /**
     * @param bool        $enabled       the setting combined with SUPERTEXT_VERSION_REPORTS: see enabled()
     * @param string|null $siteUrl       the shop's base URL; null: nothing is reported (the key check still runs)
     * @param string|null $callbackUrl   the ping endpoint's absolute URL
     * @param callable(): ?string    $load the stored state (JSON), null if none
     * @param callable(string): void $save stores the state (JSON)
     * @param HttpTransport|null $transport tests: an SDK transport for the key check and the report; null: the SDK's and Plugin Health's CurlTransport
     * @param string|null $reportUrl     tests: the center's URL (default: SUPERTEXT_REPORT_URL, else Plugin Health's)
     * @param (callable(): float)|null $now tests: wall-clock Unix seconds
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly ?string $siteUrl,
        private readonly string $pluginVersion,
        private readonly ?string $systemVersion,
        private readonly ?string $callbackUrl,
        private readonly string $apiBaseUrl,
        callable $load,
        callable $save,
        private readonly ?HttpTransport $transport = null,
        private readonly ?string $reportUrl = null,
        ?callable $now = null,
        private readonly string $systemName = self::SYSTEM_NAME,
    ) {
        $this->load = $load;
        $this->save = $save;
        $this->now  = $now;
    }

    /** Whether reports are on: SUPERTEXT_VERSION_REPORTS (0/false/off/no or 1/true/on/yes) wins over the setting. */
    public static function enabled(?bool $setting): bool
    {
        return InstallationReporter::reportsEnabled($setting);
    }

    /** Whether SUPERTEXT_VERSION_REPORTS on this server overrides the setting. */
    public static function forcedByEnvironment(): bool
    {
        return !InstallationReporter::reportsEnabled(true) || InstallationReporter::reportsEnabled(false);
    }

    /** The answer of the public ping endpoint (status, headers and body as Plugin Health returns them). */
    public static function ping(?string $challenge, bool $enabled): PingResponse
    {
        return InstallationReporter::pingResponse($challenge, self::PLUGIN_NAME, $enabled);
    }

    /** The ping endpoint's URL for a shop's base link URL ("https://shop.ch/" or "https://shop.ch/index.php/"). */
    public static function pingUrl(?string $baseLinkUrl): ?string
    {
        if ($baseLinkUrl === null || trim($baseLinkUrl) === '') {
            return null;
        }

        return rtrim(trim($baseLinkUrl), '/') . '/supertext/ping';
    }

    /** After the settings were saved: checks the key, reports `installed` or `settings_saved`. */
    public function afterSettingsSaved(#[\SensitiveParameter] string $apiKey): ?ReporterResult
    {
        return $this->run(static fn (InstallationReporter $r, ReporterState $s): ReporterResult => $r->afterSettingsSaved($s, $apiKey));
    }

    /** Admin requests that run the module's code: checks a changed key, reports what changed. */
    public function onRequest(#[\SensitiveParameter] string $apiKey): ?ReporterResult
    {
        return $this->run(static fn (InstallationReporter $r, ReporterState $s): ReporterResult => $r->onRequest($s, $apiKey));
    }

    /** A translation got 401/403: the key is invalid (reported once, when the status changes). */
    public function authenticationFailed(#[\SensitiveParameter] string $apiKey, int $httpStatus = 401): ?ReporterResult
    {
        return $this->run(static fn (InstallationReporter $r, ReporterState $s): ReporterResult => $r->authenticationFailed($s, $apiKey, $httpStatus));
    }

    /** The configuration's *Test connection*: checks the key, reports a change. */
    public function testConnection(#[\SensitiveParameter] string $apiKey): ?ReporterResult
    {
        return $this->run(static fn (InstallationReporter $r, ReporterState $s): ReporterResult => $r->testConnection($s, $apiKey));
    }

    /** supertext:check-like commands and tests: sends a `check` report now. */
    public function reportCheck(): ?ReporterResult
    {
        return $this->run(static fn (InstallationReporter $r, ReporterState $s): ReporterResult => $r->reportCheck($s));
    }

    /**
     * "Send configuration to Supertext": one `extensions` report with the installed modules and the
     * language mapping, sent now (also within the hour after a failed report). Nothing with reports off.
     *
     * @param list<array{name: string, version?: ?string, active?: ?bool, type?: ?string}> $extensions
     * @param list<array{code: string, supertext: ?string}>                                $languages
     */
    public function sendConfiguration(array $extensions, array $languages): ?ReporterResult
    {
        return $this->run(
            static fn (InstallationReporter $r, ReporterState $s): ReporterResult => $r->sendExtensions($s, $extensions),
            static fn (): array => $languages,
        );
    }

    /** The stored state (empty if none or unreadable). */
    public function state(): ReporterState
    {
        try {
            return ReporterState::from(($this->load)());
        } catch (\Throwable) {
            return ReporterState::empty();
        }
    }

    /** The latest stored key check, for the settings page. */
    public function lastCheck(): ?KeyCheck
    {
        return $this->state()->keyCheck;
    }

    /** @param (callable(): list<array{code: string, supertext: ?string}>)|null $languages the language mapping (sendConfiguration only) */
    public function reporter(?callable $languages = null): InstallationReporter
    {
        return new InstallationReporter(
            enabled: $this->enabled && $this->siteUrl !== null,
            siteUrl: $this->siteUrl ?? '',
            pluginName: self::PLUGIN_NAME,
            pluginVersion: $this->pluginVersion,
            systemName: $this->systemName,
            keyChecker: new CallableKeyChecker(
                fn (string $apiKey) => (new Client(
                    $apiKey,
                    baseUrl: $this->apiBaseUrl,
                    userAgent: self::PLUGIN_NAME . '/' . $this->pluginVersion,
                    transport: $this->transport,
                    now: $this->now,
                ))->checkApiKey(),
                [Protocol::class, 'apiKeyFingerprint'],
            ),
            systemVersion: $this->systemVersion,
            sdkVersion: Supertext::SDK_VERSION,
            callbackUrl: $this->callbackUrl,
            reportUrl: $this->reportUrl,
            transport: $this->transport === null ? null : new ReportTransport($this->transport),
            now: $this->now,
            languages: $languages,
        );
    }

    /** @param \Closure(InstallationReporter, ReporterState): ReporterResult $action */
    private function run(\Closure $action, ?callable $languages = null): ?ReporterResult
    {
        try {
            $result = $action($this->reporter($languages), $this->state());
        } catch (\Throwable) {
            return null;
        }

        try {
            ($this->save)((string) json_encode($result->state, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG));
        } catch (\Throwable) {
            // The state couldn't be stored: next time again.
        }

        return $result;
    }
}
