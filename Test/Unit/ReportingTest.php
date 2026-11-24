<?php

declare(strict_types=1);

namespace Supertext\Translation\Test\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\Sdk\Http\HttpRequest;
use Supertext\Sdk\Http\HttpResponse;
use Supertext\Sdk\Http\HttpTransport;
use Supertext\Sdk\Supertext;
use Supertext\Translation\Api\Reporting;

/**
 * The module's side of the Commando Center: state kept as JSON, the reporter actions, the ping.
 * Supertext and the center are a fake transport; nothing goes over the network.
 */
final class ReportingTest extends TestCase
{
    private const KEY    = 'secret-test-key';
    private const CENTER = 'https://center.test/api/v1/report';

    private ?string $state = null;

    /** @var list<HttpRequest> */
    private array $calls = [];

    private int $keyStatus = 200;

    protected function tearDown(): void
    {
        putenv('SUPERTEXT_VERSION_REPORTS');
    }

    private function transport(): HttpTransport
    {
        return new class ($this) implements HttpTransport {
            public function __construct(private readonly ReportingTest $test)
            {
            }

            public function send(HttpRequest $request): HttpResponse
            {
                return $this->test->answer($request);
            }
        };
    }

    /** @internal the fake transport's answers */
    public function answer(HttpRequest $request): HttpResponse
    {
        $this->calls[] = $request;

        return match (true) {
            $request->url === self::CENTER                    => new HttpResponse(202, '{"ok":true}'),
            str_starts_with($request->url, 'https://api.test/') => new HttpResponse($this->keyStatus, $this->keyStatus === 200 ? '[]' : '{"error_code":"UNAUTHORIZED"}'),
            default                                           => new HttpResponse(404, ''),
        };
    }

    private function reporting(bool $enabled = true, string $version = '1.0.0', ?callable $load = null, ?callable $save = null): Reporting
    {
        return new Reporting(
            enabled: $enabled,
            siteUrl: 'https://shop.example.ch/',
            pluginVersion: $version,
            systemVersion: '2.4.8',
            callbackUrl: Reporting::pingUrl('https://shop.example.ch/'),
            apiBaseUrl: 'https://api.test/v1/',
            load: $load ?? fn (): ?string => $this->state,
            save: $save ?? function (string $json): void {
                $this->state = $json;
            },
            transport: $this->transport(),
            reportUrl: self::CENTER,
            now: static fn (): float => 1792310400.0,
        );
    }

    /** @return list<array<string, mixed>> */
    private function reports(): array
    {
        return array_values(array_map(
            static fn (HttpRequest $c): array => json_decode((string) $c->body, true),
            array_filter($this->calls, static fn (HttpRequest $c): bool => $c->url === self::CENTER)
        ));
    }

    public function testSettingsSavedChecksTheKeyAndReportsTheInstallation(): void
    {
        $result = $this->reporting()->afterSettingsSaved(self::KEY);

        self::assertNotNull($result);
        self::assertSame('ok', $result->check?->status);
        self::assertSame('installed', $result->event);
        self::assertTrue($result->sent);

        $report = $this->reports()[0];
        self::assertSame('https://shop.example.ch/', $report['site_url']);
        self::assertSame(['name' => 'Magento-Supertext-Translation', 'version' => '1.0.0'], $report['plugin']);
        self::assertSame(['name' => 'Magento', 'version' => '2.4.8'], $report['system']);
        self::assertSame('PHP', $report['runtime']['name']);
        self::assertSame(Supertext::SDK_VERSION, $report['sdk_version']);
        self::assertTrue($report['api_key_configured']);
        self::assertSame('ok', $report['api_key_check']['status']);
        self::assertSame(['url' => 'https://shop.example.ch/supertext/ping'], $report['callback']);

        // The key never leaves the shop: not in the report, not in the stored state.
        foreach ($this->calls as $call) {
            self::assertStringNotContainsString(self::KEY, (string) $call->body);
        }
        self::assertStringNotContainsString(self::KEY, (string) $this->state);
        self::assertSame('ok', $this->reporting()->lastCheck()?->status);

        // A second save reports settings_saved.
        self::assertSame('settings_saved', $this->reporting()->afterSettingsSaved(self::KEY)?->event);
    }

    public function testBackEndRequestsReportOnlyWhatChanged(): void
    {
        $this->reporting()->afterSettingsSaved(self::KEY);
        $this->calls = [];

        $result = $this->reporting()->onRequest(self::KEY);
        self::assertNull($result?->event);
        self::assertSame([], $this->calls, 'same key, same versions: no request at all');

        self::assertSame('upgraded', $this->reporting(version: '1.1.0')->onRequest(self::KEY)?->event);
        self::assertSame('1.1.0', $this->reports()[0]['plugin']['version']);

        // A new key (e.g. a changed SUPERTEXT_API_KEY) is checked once.
        $this->calls = [];
        $this->keyStatus = 401;
        $changed = $this->reporting(version: '1.1.0')->onRequest('another-key');
        self::assertSame('invalid', $changed?->check?->status);
        self::assertSame('api_key_check', $changed?->event);
    }

    public function testAStateStoredByTheSdkReporterIsKept(): void
    {
        // As the Supertext SDK 0.2 reporter stored it: switching to Plugin Health sends no new `installed`.
        $this->state = '{"reportedVersions":"1.0.0|2.4.8|' . PHP_VERSION . '","reportedKeyStatus":"ok","lastFailureAt":null,'
            . '"keyCheck":{"status":"ok","checkedAt":"2026-10-14T08:00:00Z","httpStatus":200,"errorCode":null,"fingerprint":"' . hash('sha256', self::KEY) . '"}}';
        $before = $this->state;

        self::assertNull($this->reporting()->onRequest(self::KEY)?->event);
        self::assertSame([], $this->calls);
        // Plugin Health 0.2+ adds keyChecks and reportedKeyStatuses (null with one key); everything else is kept.
        self::assertSame(json_decode($before, true) + ['keyChecks' => null, 'reportedKeyStatuses' => null], json_decode((string) $this->state, true), 'stored in the same format');
    }

    public function testSendConfigurationSendsModulesAndTheLanguageMapping(): void
    {
        $modules   = [['name' => 'amasty/module-gift-card', 'version' => '2.14.0', 'active' => true, 'type' => 'module'], ['name' => 'Vendor_Old', 'version' => null, 'active' => false, 'type' => 'module']];
        $languages = [['code' => 'default:en_US', 'supertext' => 'en-US'], ['code' => 'de:de_CH', 'supertext' => 'de-CH'], ['code' => 'ie:en_IE', 'supertext' => null]];

        $result = $this->reporting()->sendConfiguration($modules, $languages);

        self::assertSame('extensions', $result?->event);
        self::assertTrue($result->sent);
        [$report] = $this->reports();
        self::assertSame('extensions', $report['event']);
        // A module without a version is sent without the field.
        self::assertSame([$modules[0], ['name' => 'Vendor_Old', 'active' => false, 'type' => 'module']], $report['extensions']);
        self::assertSame($languages, $report['languages']);
    }

    public function testSendConfigurationSendsNothingWithReportsOff(): void
    {
        self::assertFalse((bool) $this->reporting(enabled: false)->sendConfiguration([['name' => 'a/b']], [['code' => 'de:de_CH', 'supertext' => 'de-CH']])?->sent);
        self::assertSame([], $this->reports());
    }

    public function testRejectedTranslationIsReportedOnce(): void
    {
        $this->reporting()->afterSettingsSaved(self::KEY);
        $this->calls = [];

        $first = $this->reporting()->authenticationFailed(self::KEY, 401);
        self::assertSame('api_key_check', $first?->event);
        self::assertSame('invalid', $this->reports()[0]['api_key_check']['status']);
        self::assertSame([self::CENTER], array_map(static fn (HttpRequest $c): string => $c->url, $this->calls), 'no request to Supertext');

        self::assertNull($this->reporting()->authenticationFailed(self::KEY, 401)?->event);
        self::assertCount(1, $this->reports());
    }

    public function testTestConnectionShowsTheCheck(): void
    {
        $this->keyStatus = 401;

        self::assertSame('invalid', $this->reporting()->testConnection(self::KEY)?->check?->status);
        self::assertSame('missing', $this->reporting()->testConnection('')?->check?->status);
    }

    public function testNothingIsSentWithReportsOff(): void
    {
        $result = $this->reporting(enabled: false)->afterSettingsSaved(self::KEY);

        self::assertSame('ok', $result?->check?->status, 'the key check still runs');
        self::assertSame([], $this->reports());
        self::assertFalse($result?->sent);
    }

    public function testStorageProblemsNeverEscape(): void
    {
        $broken = $this->reporting(
            load: static fn (): ?string => throw new \RuntimeException('database down'),
            save: static fn (string $json) => throw new \RuntimeException('database down'),
        );

        self::assertSame('ok', $broken->afterSettingsSaved(self::KEY)?->check?->status);
        self::assertNull($broken->lastCheck());

        $garbage = $this->reporting(load: static fn (): ?string => '{not json');
        self::assertSame('installed', $garbage->onRequest(self::KEY)?->event);
    }

    public function testPing(): void
    {
        $ok = Reporting::ping('abcdefghijklmnop_1234', true);
        self::assertSame(200, $ok->status);
        self::assertSame(['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], $ok->headers);
        self::assertSame('{"ok":true,"plugin":"Magento-Supertext-Translation","challenge":"abcdefghijklmnop_1234"}', $ok->body);

        self::assertSame(400, Reporting::ping('<script>', true)->status);
        self::assertSame(400, Reporting::ping(null, true)->status);
        self::assertSame(404, Reporting::ping('abcdefghijklmnop_1234', false)->status);
    }

    public function testPingUrl(): void
    {
        self::assertSame('https://shop.example.ch/supertext/ping', Reporting::pingUrl('https://shop.example.ch/'));
        self::assertSame('https://shop.example.ch/index.php/supertext/ping', Reporting::pingUrl('https://shop.example.ch/index.php/'));
        self::assertNull(Reporting::pingUrl(null));
        self::assertNull(Reporting::pingUrl(' '));
    }

    public function testTheEnvironmentVariableWinsOverTheSetting(): void
    {
        putenv('SUPERTEXT_VERSION_REPORTS');
        self::assertTrue(Reporting::enabled(true));
        self::assertTrue(Reporting::enabled(null));
        self::assertFalse(Reporting::enabled(false));
        self::assertFalse(Reporting::forcedByEnvironment());

        putenv('SUPERTEXT_VERSION_REPORTS=0');
        self::assertFalse(Reporting::enabled(true));
        self::assertTrue(Reporting::forcedByEnvironment());

        putenv('SUPERTEXT_VERSION_REPORTS=on');
        self::assertTrue(Reporting::enabled(false));
    }

    public function testWithoutASiteUrlNothingIsReported(): void
    {
        $reporting = new Reporting(true, null, '1.0.0', '2.4.8', null, 'https://api.test/v1/', fn (): ?string => null, static function (string $json): void {
        }, $this->transport(), self::CENTER);

        self::assertSame('ok', $reporting->afterSettingsSaved(self::KEY)?->check?->status);
        self::assertSame([], $this->reports());
    }
}
