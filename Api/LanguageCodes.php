<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Api;

use Supertext\Sdk\V1\Languages;
use Supertext\Sdk\V1\SupertextException as SdkException;

/**
 * Store view locales → Supertext language codes, without guessing (the Supertext SDK's rules, the
 * same in every Supertext plugin).
 *
 * Supertext accepts exactly the codes of its `GET features` list: bare sources ("en"); targets
 * regional only for German, English, French, Italian and Portuguese ("de-CH", "en-US", "pt-BR"),
 * with a script for Chinese and Serbian ("zh-Hans", "sr-Latn"), bare for the others ("es", "nl").
 *
 * A store view's *Supertext language* (a list of Supertext's codes under Stores → Configuration →
 * Supertext → Translation, store view scope) wins; otherwise a locale that equals a Supertext code
 * apart from case and "_"/"-" is used ("de_CH" → "de-CH"); otherwise the SDK's default where one is
 * obvious ("es_ES" → "es", "nl_NL" → "nl", "zh_Hans_CN" → "zh-Hans",
 * "zh_Hant_TW" → "zh-Hant"); anything else ("en_IE", "de_LI", "gsw") is the error
 * `unsupported_language` and nothing is sent. The same setting serves the source: a mapped "de-CH"
 * is sent as "de".
 *
 * No Magento dependencies.
 */
final class LanguageCodes
{
    /**
     * Every target Supertext offers, sorted by English name: code, English name, whether it has formal/informal.
     *
     * @return list<array{code: string, name: string, politeness: bool}>
     */
    public static function targets(): array
    {
        return Languages::targetOptions();
    }

    /**
     * The *Supertext language* list: "Name (code)" per Supertext target, the name in $displayLocale
     * (the admin's interface locale, e.g. "de_CH") where PHP's intl extension knows it, else English;
     * sorted by that name.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(string $displayLocale = 'en_US'): array
    {
        $options = [];

        foreach (Languages::targetOptions() as $language) {
            $options[] = ['value' => $language['code'], 'label' => self::displayName($language['code'], $displayLocale) . ' (' . $language['code'] . ')'];
        }

        if (class_exists(\Collator::class)) {
            $collator = new \Collator($displayLocale);
            usort($options, static fn (array $a, array $b): int => (int) $collator->compare($a['label'], $b['label']));
        } else {
            usort($options, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
        }

        return $options;
    }

    /**
     * A target's name in $displayLocale ("de-CH", "de_DE" → "Deutsch (Schweiz)"); English (the SDK's
     * name) for English, without intl, and for codes intl names badly (the Swiss German dialects).
     */
    public static function displayName(string $code, string $displayLocale = 'en_US'): string
    {
        $english = self::name($code);
        $english = $english !== '' ? $english : trim($code);

        if (!class_exists(\Locale::class) || str_starts_with(strtolower($displayLocale), 'en') || str_contains($code, '-u-')) {
            return $english;
        }

        $locale = str_replace('-', '_', trim($code));
        $name   = (string) \Locale::getDisplayName($locale, $displayLocale);

        // intl returns the code itself for a code it doesn't know.
        return $name !== '' && strcasecmp($name, trim($code)) !== 0 && strcasecmp($name, $locale) !== 0 ? $name : $english;
    }

    /**
     * What an empty *Supertext language* means for a store view: the locale when it is a Supertext
     * code, else the SDK's default ("de_DE" → "de-DE", "es_ES" → "es"), else null ("en_IE": choose one).
     */
    public static function suggest(string $locale): ?string
    {
        return Languages::suggestTarget($locale);
    }

    /** Whether the locale itself is a Supertext code ("de_CH", "de_DE"), as opposed to having only a default ("es_ES" → "es"). */
    public static function isExactMatch(string $locale): bool
    {
        return Languages::matchTarget($locale) !== null;
    }

    /** The code as Supertext lists it ("de_ch" → "de-CH"), or null when it isn't one of Supertext's targets. */
    public static function canonical(string $code): ?string
    {
        return Languages::matchTarget($code);
    }

    /** The Supertext target that equals the locale apart from case and "_"/"-", else null ("de_DE" → "de-DE", "es_ES" → null). */
    public static function match(string $locale): ?string
    {
        return Languages::matchTarget($locale);
    }

    /**
     * Target code to send: the store view's Supertext language, else an exact match of its locale,
     * else the SDK's default for it.
     *
     * @throws SupertextException unsupported_language when there is neither (nothing is sent)
     */
    public static function target(string $locale, string $override = ''): string
    {
        try {
            return Languages::target(self::code($locale, $override), self::mapping($locale, $override));
        } catch (SdkException $e) {
            throw Supertext::error($e);
        }
    }

    /** The target code, or null when the store view has neither a Supertext language nor a default (settings, translate page). */
    public static function targetOrNull(string $locale, string $override = ''): ?string
    {
        try {
            return self::target($locale, $override);
        } catch (SupertextException) {
            return null;
        }
    }

    /**
     * Source code to send: the source form of the Supertext language, else of the locale
     * ("en_GB" → "en"). Empty stays empty (auto-detect).
     *
     * @throws SupertextException unsupported_language when there is neither
     */
    public static function source(string $locale, string $override = ''): string
    {
        try {
            return Languages::source(self::code($locale, $override), self::mapping($locale, $override));
        } catch (SdkException $e) {
            throw Supertext::error($e);
        }
    }

    /** The source code, or null when there is none. */
    public static function sourceOrNull(string $locale, string $override = ''): ?string
    {
        try {
            return self::source($locale, $override);
        } catch (SupertextException) {
            return null;
        }
    }

    /** Whether Supertext offers formal/informal for a target code (null: a code the SDK's list doesn't know). */
    public static function supportsPoliteness(string $target): ?bool
    {
        return Languages::supportsPoliteness($target);
    }

    /** The English name of a target code ("de-CH" → "German (Switzerland)"), or "" for a code the list doesn't know. */
    public static function name(string $target): string
    {
        foreach (Languages::TARGETS as $language) {
            if (strcasecmp($language['code'], trim($target)) === 0) {
                return $language['name'];
            }
        }

        return '';
    }

    /**
     * One store view's setting as the SDK's mapping (locale → Supertext code).
     *
     * @return array<string, string>
     */
    private static function mapping(string $locale, string $override): array
    {
        return trim($override) !== '' && trim($locale) !== '' ? [trim($locale) => trim($override)] : [];
    }

    /** The locale to resolve: a store view always has one; without, its setting alone. */
    private static function code(string $locale, string $override): string
    {
        return trim($locale) !== '' ? $locale : $override;
    }
}
