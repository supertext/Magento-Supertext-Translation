<?php

declare(strict_types=1);

namespace Supertext\Translation\Test\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\Translation\Api\LanguageCodes;
use Supertext\Translation\Api\SupertextException;

/**
 * Magento locales → Supertext codes, as Config::targetCode() and sourceCode() use them: the store
 * view's Supertext language, else an exact match of the locale, else the SDK's default, else
 * unsupported_language. Nothing else is guessed.
 */
final class LanguageCodesTest extends TestCase
{
    public function testLocalesThatAreSupertextCodesAreUsedAsTheyAre(): void
    {
        self::assertSame('de-CH', LanguageCodes::target('de_CH'));
        self::assertSame('fr-FR', LanguageCodes::target('fr_fr'));
        self::assertSame('it-CH', LanguageCodes::target('it_CH'));
        self::assertSame('pt-BR', LanguageCodes::target('pt_BR'));
        self::assertSame('zh-Hans', LanguageCodes::target('zh_hans'));
    }

    public function testLocalesWithoutAnExactMatchUseTheSdkDefault(): void
    {
        self::assertSame('es', LanguageCodes::target('es_ES'));
        self::assertSame('nl', LanguageCodes::target('nl_NL'));
        self::assertSame('sl', LanguageCodes::target('sl_SI'));
        self::assertSame('nb', LanguageCodes::target('nb_NO'));
        self::assertSame('zh-Hans', LanguageCodes::target('zh_Hans_CN'));
        self::assertSame('zh-Hant', LanguageCodes::target('zh_Hant_TW'));
        self::assertSame('de-DE', LanguageCodes::target('de'));
        self::assertSame('es', LanguageCodes::suggest('es_ES'));
        self::assertSame('de-CH', LanguageCodes::suggest('de_CH'));
        self::assertTrue(LanguageCodes::isExactMatch('de_DE'));
        self::assertFalse(LanguageCodes::isExactMatch('es_ES'));
    }

    public function testNoGuessingWithoutADefault(): void
    {
        foreach (['en_IE', 'de_LI', 'fr_BE', 'gsw', 'xx_YY'] as $locale) {
            self::assertNull(LanguageCodes::targetOrNull($locale), $locale);
            self::assertNull(LanguageCodes::suggest($locale), $locale);
        }

        try {
            LanguageCodes::target('en_IE');
            self::fail('expected unsupported_language');
        } catch (SupertextException $e) {
            self::assertStringStartsWith('The language "%1" has no Supertext language yet.', $e->template());
            self::assertSame(['en_IE'], $e->parameters());
            self::assertStringContainsString('Supertext language list under Stores → Configuration → Supertext → Translation → Language', $e->getMessage());
        }
    }

    public function testTheListHasEveryTargetAsNameAndCode(): void
    {
        $options = LanguageCodes::options('en_US');
        $labels  = array_column($options, 'label', 'value');

        self::assertCount(\count(\Supertext\Sdk\V1\Languages::TARGETS), $options);
        self::assertSame('German (Switzerland) (de-CH)', $labels['de-CH']);
        self::assertSame('Swiss German (Zurich) (gsw-u-sd-chzh)', $labels['gsw-u-sd-chzh']);
        self::assertSame('Albanian (sq)', $options[0]['label'], 'sorted by name');
        self::assertSame('de-CH', LanguageCodes::canonical('de_ch'));
        self::assertNull(LanguageCodes::canonical('de'));

        if (class_exists(\Locale::class)) {
            self::assertSame('Deutsch (Schweiz)', LanguageCodes::displayName('de-CH', 'de_CH'));
            self::assertContains('Spanisch (es)', array_column(LanguageCodes::options('de_DE'), 'label'));
        }
    }

    public function testTheStoreViewSettingWins(): void
    {
        self::assertSame('es', LanguageCodes::target('es_ES', 'es'));
        self::assertSame('fr-CH', LanguageCodes::target('fr_FR', 'fr-CH'));
        self::assertSame('nb', LanguageCodes::target('nb_NO', ' nb '));
        // A code Supertext adds later is sent as it is.
        self::assertSame('uk', LanguageCodes::target('uk_UA', 'uk'));
        self::assertSame('de-CH', LanguageCodes::target('', 'de-CH'));
    }

    public function testSources(): void
    {
        self::assertSame('en', LanguageCodes::source('en_US'));
        self::assertSame('en', LanguageCodes::source('en_GB'));
        self::assertSame('de', LanguageCodes::source('de_CH'));
        self::assertSame('zh', LanguageCodes::source('zh_Hans_CN'));
        self::assertSame('', LanguageCodes::source(''));
        // One setting for both directions: a mapped target is sent in its source form.
        self::assertSame('de', LanguageCodes::source('xx_XX', 'de-CH'));
        self::assertNull(LanguageCodes::sourceOrNull('xx_XX'));

        try {
            LanguageCodes::source('xx_XX');
            self::fail('expected unsupported_language');
        } catch (SupertextException $e) {
            self::assertStringStartsWith('The source language "%1"', $e->template());
            self::assertSame(['xx_XX'], $e->parameters());
        }
    }

    public function testPolitenessAndNames(): void
    {
        self::assertTrue(LanguageCodes::supportsPoliteness('de-CH'));
        self::assertFalse(LanguageCodes::supportsPoliteness('en-US'));
        self::assertFalse(LanguageCodes::supportsPoliteness('gsw-u-sd-chzh'));
        self::assertNull(LanguageCodes::supportsPoliteness('uk'));
        self::assertSame('German (Switzerland)', LanguageCodes::name('de-CH'));
        self::assertSame('', LanguageCodes::name('uk'));
        self::assertContains('de-CH', array_column(LanguageCodes::targets(), 'code'));
        self::assertSame('de-CH', LanguageCodes::match('de_ch'));
        self::assertNull(LanguageCodes::match('de'));
    }
}
