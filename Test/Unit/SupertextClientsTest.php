<?php

declare(strict_types=1);

namespace Supertext\Translation\Test\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\Sdk\Http\HttpRequest;
use Supertext\Sdk\Http\HttpResponse;
use Supertext\Sdk\Http\HttpTransport;
use Supertext\Sdk\V1\Client;
use Supertext\Translation\Api\Supertext;
use Supertext\Translation\Api\SupertextClients;

/**
 * Translation review: store views with it on are translated through the review service (all four
 * calls, same key and User-Agent), the others directly; an empty review URL turns it off; the
 * default is Supertext's review service.
 */
final class SupertextClientsTest extends TestCase
{
    /** @var list<HttpRequest> */
    private array $requests = [];

    private function clients(string $reviewUrl): SupertextClients
    {
        $transport = new class ($this->requests) implements HttpTransport {
            /** @param list<HttpRequest> $requests */
            public function __construct(private array &$requests)
            {
            }

            public function send(HttpRequest $r): HttpResponse
            {
                $this->requests[] = $r;

                return match (true) {
                    $r->method === 'POST'                  => new HttpResponse(200, '{"file_id":"f1"}'),
                    str_ends_with($r->url, '/status')      => new HttpResponse(200, '{"status":"done"}'),
                    str_ends_with($r->url, '/translation') => new HttpResponse(200, '<div data-st-id="0">Hallo</div>'),
                    default                                => new HttpResponse(204),
                };
            }
        };

        return new SupertextClients(
            static fn (string $baseUrl): Client => Supertext::client('test-key', $baseUrl, 60, '1.2.3', $transport, 0.0, static function (): void {
            }),
            'https://api.test/v1/',
            $reviewUrl,
        );
    }

    /** @return list<string> "METHOD host" per request since $from */
    private function calls(int $from = 0): array
    {
        return array_map(
            static fn (HttpRequest $r): string => $r->method . ' ' . parse_url($r->url, \PHP_URL_HOST),
            \array_slice($this->requests, $from),
        );
    }

    public function testReviewStoreViewsGoThroughTheReviewServiceOthersDirectly(): void
    {
        $clients = $this->clients('https://review.test/v1/');

        self::assertTrue($clients->reviewAvailable());
        self::assertSame(['t' => 'Hallo'], $clients->forReview(true)->translateTexts(['t' => 'Hello'], 'de-CH', 'en'));
        self::assertSame(['POST review.test', 'GET review.test', 'GET review.test', 'DELETE review.test'], $this->calls(), 'upload, status, download and delete through the review service');
        self::assertSame('Supertext-Auth-Key test-key', $this->requests[0]->headers['Authorization'], 'same key');
        self::assertStringContainsString('Magento-Supertext-Translation/1.2.3', $this->requests[0]->headers['User-Agent']);
        self::assertSame('https://review.test/v1/translate/ai/file', $this->requests[0]->url);

        $clients->forReview(false)->translateTexts(['t' => 'Hello'], 'fr-CH', 'en');
        self::assertSame(['POST api.test', 'GET api.test', 'GET api.test', 'DELETE api.test'], $this->calls(4), 'directly to Supertext');

        self::assertSame($clients->forReview(true), $clients->forReview(true), 'one review client');
        self::assertNotSame($clients->forReview(true), $clients->forReview(false));
    }

    public function testEmptyReviewUrlOrTheApiUrlMeansNoReviewService(): void
    {
        foreach (['', '  ', 'https://api.test/v1'] as $url) {
            $clients = $this->clients($url);
            self::assertFalse($clients->reviewAvailable(), $url);
            self::assertSame($clients->forReview(false), $clients->forReview(true), $url);
        }

        $this->clients('')->forReview(true)->translateTexts(['t' => 'Hello'], 'de-CH', 'en');
        self::assertSame(['POST api.test', 'GET api.test', 'GET api.test', 'DELETE api.test'], $this->calls());
    }

    public function testTheDefaultIsSupertextsReviewServiceAndReviewIsOff(): void
    {
        $config = simplexml_load_file(\dirname(__DIR__, 2) . '/etc/config.xml');

        self::assertSame(Supertext::REVIEW_URL, (string) $config->default->supertext->api->review_url);
        self::assertSame('0', (string) $config->default->supertext->language->review);
        self::assertSame('https://localizationreview-production.up.railway.app/v1/', Supertext::REVIEW_URL);
        self::assertSame(Supertext::REVIEW_URL, Supertext::reviewBaseUrl(Supertext::REVIEW_URL, Supertext::LIVE));
        self::assertNull(Supertext::reviewBaseUrl(Supertext::LIVE, Supertext::LIVE));
    }
}
