<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Api;

use Supertext\Sdk\Http\HttpTransport;
use Supertext\Sdk\V1\Client;
use Supertext\Sdk\V1\Protocol;
use Supertext\Sdk\V1\SupertextException as SdkException;

/**
 * The module's access to Supertext: the vendored Supertext SDK (lib/supertext-sdk,
 * https://github.com/Supertext/Supertext-API-SDK-and-Guide) with this module's defaults, and its
 * errors turned into messages the admin translates. The SDK handles the protocol: auth header,
 * multipart upload, HTTP 429 retries, polling, cleanup and packing texts into
 * documents.
 *
 * No Magento dependencies.
 */
final class Supertext
{
    public const LIVE = Protocol::LIVE_BASE_URL;
    public const STAGING = 'https://api.staging.supertext.com/v1/';
    public const TESTING = 'https://api.testing.supertext.com/v1/';

    /**
     * Supertext's review service (translation proxy of Supertext Localization Review). It speaks the
     * same API and forwards to the live Supertext API with the module's key, keeping every translation
     * so reviewers can correct them on the website. Used for store views with *Translation review* on.
     */
    public const REVIEW_URL = 'https://localizationreview-production.up.railway.app/v1/';

    public const SIGNUP_URL  = 'https://www.supertext.com/person/en/account/signin';
    public const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

    /** A client that identifies the module in the User-Agent. */
    public static function client(
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl,
        int $timeout,
        string $version,
        ?HttpTransport $transport = null,
        float $pollInterval = 2.0,
        ?callable $sleep = null,
    ): Client {
        return new Client(
            self::normalizeKey($apiKey),
            baseUrl: $baseUrl,
            pollInterval: $pollInterval,
            timeout: (float) $timeout,
            userAgent: 'Magento-Supertext-Translation/' . ($version !== '' ? $version : 'dev'),
            transport: $transport,
            sleep: $sleep,
        );
    }

    /**
     * The review service's base URL for $baseUrl, or null when review is off: an empty $reviewUrl,
     * or the same URL as the API (then there is no second client).
     */
    public static function reviewBaseUrl(string $reviewUrl, string $baseUrl): ?string
    {
        $reviewUrl = trim($reviewUrl);

        return $reviewUrl === '' || rtrim($reviewUrl, '/') === rtrim(trim($baseUrl), '/') ? null : $reviewUrl;
    }

    /** Supertext shows the key as "Supertext-Auth-Key <key>"; accept it with or without that prefix. */
    public static function normalizeKey(#[\SensitiveParameter] string $key): string
    {
        return (string) preg_replace('/^Supertext-Auth-Key\s+/i', '', trim($key));
    }

    public static function baseUrlFor(string $environment, string $customEndpoint = ''): string
    {
        if (trim($customEndpoint) !== '') {
            return trim($customEndpoint);
        }

        return match ($environment) {
            'staging' => self::STAGING,
            'testing' => self::TESTING,
            default   => self::LIVE,
        };
    }

    /**
     * The SDK's error as the module's exception: an English template from i18n/*.csv with its
     * parameters, so the admin shows it in the user's language. Supertext's own detail is kept,
     * except for language pairs, where the message already names both codes: Supertext's own, or
     * else the codes that were sent ($source, $target).
     */
    public static function error(SdkException $e, string $source = '', string $target = ''): SupertextException
    {
        $links = [self::SIGNUP_URL, self::API_KEY_URL];

        [$message, $parameters] = match ($e->errorCode) {
            SdkException::MISSING_API_KEY        => ['Supertext is not set up yet: an administrator needs to enter the API key in Stores → Configuration → Supertext → Translation. No Supertext account yet? Create one at %1. Generate your API key at %2 (requires the Admin role).', $links],
            SdkException::AUTHENTICATION_FAILURE => ['Authentication failed. Please check the Supertext API key. No Supertext account yet? Create one at %1. Generate your API key at %2 (requires the Admin role).', $links],
            SdkException::INVALID_LANGUAGE_PAIR  => self::languagePair($e, $source, $target),
            SdkException::UNSUPPORTED_LANGUAGE   => self::unsupportedLanguage($e),
            SdkException::UNSUPPORTED_FEATURE    => ['Supertext offers no formal or informal tone for this language. Set its tone to "Supertext default" under Stores → Configuration → Supertext → Translation → Language.', []],
            SdkException::NOT_FOUND              => ['The requested Supertext resource was not found.', []],
            SdkException::PAYLOAD_TOO_LARGE      => ['The content is too large for Supertext to translate in one go.', []],
            SdkException::TOO_MANY_REQUESTS      => ['Too many requests to Supertext. Please try again shortly.', []],
            SdkException::SERVICE_UNAVAILABLE    => ['The Supertext service is currently unavailable.', []],
            SdkException::TRANSPORT_ERROR        => ['Could not reach Supertext.', []],
            SdkException::NO_FILE_ID             => ['Supertext did not return a file id.', []],
            SdkException::TRANSLATION_ERROR      => ['Supertext could not translate the document.', []],
            SdkException::QUOTA_EXCEEDED         => ['Your Supertext translation limit is exceeded. Please upgrade your subscription.', []],
            SdkException::FILE_DELETED           => ['The document was deleted at Supertext before it could be downloaded.', []],
            SdkException::TIMEOUT                => ['Timed out waiting for the Supertext translation.', []],
            SdkException::INCOMPLETE_RESPONSE    => ['The Supertext translation came back incomplete; nothing was saved for this item.', []],
            default                              => ['Supertext answered with HTTP %1.', [(string) ($e->status ?? 0)]],
        };

        $detail = \in_array($e->errorCode, [SdkException::INVALID_LANGUAGE_PAIR, SdkException::UNSUPPORTED_LANGUAGE, SdkException::UNSUPPORTED_FEATURE], true) ? '' : (string) $e->detail;

        return new SupertextException($message, $parameters, (int) $e->status, $e, $detail);
    }

    /** @return array{string, list<string>} */
    private static function languagePair(SdkException $e, string $source, string $target): array
    {
        $source = (string) $e->sourceLang !== '' ? (string) $e->sourceLang : $source;
        $target = (string) $e->targetLang !== '' ? (string) $e->targetLang : $target;

        if ($source !== '' && $target !== '') {
            return [
                'Supertext does not translate from "%1" into "%2". Choose another Supertext language for this store view under Stores → Configuration → Supertext → Translation → Language (Scope: the store view).',
                [$source, $target],
            ];
        }

        return ['Supertext does not translate into this language. Choose another Supertext language for this store view under Stores → Configuration → Supertext → Translation → Language (Scope: the store view).', []];
    }

    /**
     * No Supertext code for a locale (nothing was sent): names the locale and where to map it.
     *
     * @return array{string, list<string>}
     */
    private static function unsupportedLanguage(SdkException $e): array
    {
        $code = (string) ($e->targetLang ?? $e->sourceLang ?? '');

        if ($e->targetLang === null && $e->sourceLang !== null) {
            return [
                'The source language "%1" (the default locale) has no Supertext language yet. An administrator can choose it in the Supertext language list under Stores → Configuration → Supertext → Translation → Language, with the default store view selected as Scope.',
                [$code],
            ];
        }

        return [
            'The language "%1" has no Supertext language yet. An administrator can choose it in the Supertext language list under Stores → Configuration → Supertext → Translation → Language, with this store view selected as Scope.',
            [$code],
        ];
    }
}
