<?php

declare(strict_types=1);

namespace Supertext\Translation\Test\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\Sdk\Files\FileJob;
use Supertext\Sdk\Files\FileSegment;
use Supertext\Sdk\Files\TranslationFiles;
use Supertext\Translation\Api\SupertextException;
use Supertext\Translation\Model\Translation\FieldPlanner;
use Supertext\Translation\Model\Translation\FileExchange;
use Supertext\Translation\Model\Translation\FileItems;

/** Export for translation / Import translation against a fake shop. */
final class FileExchangeTest extends TestCase
{
    private FakeItems $items;

    protected function setUp(): void
    {
        $this->items = new FakeItems();
    }

    private function exchange(): FileExchange
    {
        return new FileExchange($this->items);
    }

    public function testReferenceAndFileName(): void
    {
        self::assertSame('product:42:store-2', FileExchange::reference('product', 42, 2));
        self::assertSame(['type' => 'cms_page', 'id' => 5, 'store' => 3], FileExchange::parseReference('cms_page:5:store-3'));

        foreach (['page:12', 'product:42:2', 'product:0:store-2', 'unknown:1:store-1', 'product:42:store-2:x', 'product:42:store-0'] as $bad) {
            self::assertNull(FileExchange::parseReference($bad), $bad);
        }

        self::assertSame('magento-product-42-store-2.xlf', FileExchange::fileName('product', [42], [2], 'xliff'));
        self::assertSame('magento-product-42.xlf', FileExchange::fileName('product', [42], [2, 3], 'xliff'));
        self::assertSame('magento-product-42-and-2-more.xlf', FileExchange::fileName('product', [42, 43, 44], [2, 3], 'xliff'));
        self::assertSame('magento-cms_page-5-store-3.html', FileExchange::fileName('cms_page', [5], [3], 'html'));
        self::assertSame('application/x-xliff+xml', FileExchange::contentType('xliff'));
        self::assertSame('text/html; charset=utf-8', FileExchange::contentType('html'));
    }

    public function testExportUsesThePlannersSegmentsAndTheCodes(): void
    {
        $file  = $this->exchange()->export('product', [42], [2, 3], 'xliff', false);
        $files = TranslationFiles::read($file['content']);

        self::assertSame('magento-product-42.xlf', $file['fileName']);
        self::assertSame(2, $file['jobs']);
        self::assertSame(FileExchange::PLUGIN, $files[0]->plugin);
        self::assertSame('product:42:store-2', $files[0]->reference);
        self::assertSame(['en', 'de-CH'], [$files[0]->sourceLanguage, $files[0]->targetLanguage]);
        self::assertSame('xx-YY', $files[1]->targetLanguage, 'a store view without a Supertext language never blocks the export');
        // name is already translated in store 2 (kept without overwrite); store 3 gets everything.
        self::assertSame(['description'], array_map(static fn ($u) => $u->key, $files[0]->units));
        self::assertSame(['name', 'description'], array_map(static fn ($u) => $u->key, $files[1]->units));

        $html = $this->exchange()->export('product', [42], [3], 'html', false);
        self::assertSame('magento-product-42-store-3.html', $html['fileName']);
        self::assertStringContainsString('data-st-keep', $html['content'], 'Magento directives travel protected, as to the API');
    }

    public function testExportErrors(): void
    {
        foreach ([
            'Choose at least one store view to translate into.'                                       => fn () => $this->exchange()->export('product', [42], [], 'xliff', false),
            'An HTML file holds one item in one language: choose exactly one store view, or use XLIFF.' => fn () => $this->exchange()->export('product', [42], [2, 3], 'html', false),
            '%1 %2 does not exist.'                                                                    => fn () => $this->exchange()->export('product', [99], [2], 'xliff', false),
            'Nothing selected. Go back to the list and select the items to translate.'                 => fn () => $this->exchange()->export('product', [], [2], 'xliff', false),
        ] as $template => $call) {
            try {
                $call();
                self::fail("Expected $template");
            } catch (SupertextException $e) {
                self::assertSame($template, $e->template());
            }
        }

        $this->items->target[2] = ['name' => 'Kiste', 'description' => '<p>Handgemacht</p>'];

        try {
            $this->exchange()->export('product', [42], [2], 'xliff', false);
            self::fail('Expected nothing to translate');
        } catch (SupertextException $e) {
            self::assertSame('Nothing to translate.', $e->getMessage());
        }
    }

    public function testImportWritesOkSegmentsOnly(): void
    {
        $xliff = $this->exchange()->export('product', [42], [3], 'xliff', false)['content'];
        $xliff = self::translate($xliff, 'name', 'Box');
        $xliff = self::translate($xliff, 'description', '<bpt id="1">p</bpt>Fait main <bpt id="2">s</bpt><bpt id="3">k</bpt>{{store url=""}}<ept id="3">/k</ept><ept id="2">/s</ept><ept id="1">/p</ept>');

        $jobs = $this->exchange()->import($xliff, false, false);

        self::assertNull($jobs[0]['error']);
        self::assertSame(['product', 42, 3], [$jobs[0]['type'], $jobs[0]['id'], $jobs[0]['store']]);
        self::assertSame(2, $jobs[0]['counts']['imported']);
        self::assertSame(['Box', '<p>Fait main <strong>{{store url=""}}</strong></p>'], array_values($this->items->written[0][3]), 'directives unprotected by the planner');
    }

    public function testSourceChangedOnlyWithTheOptionAndOthersNeverWritten(): void
    {
        $xliff = $this->exchange()->export('product', [42], [3], 'xliff', false)['content'];
        $xliff = self::translate($xliff, 'name', 'Box');
        $this->items->source['name'] = 'Praline box (new)';

        $jobs = $this->exchange()->import($xliff, false, false);
        self::assertSame(['imported' => 0, 'sourceChanged' => 1, 'untranslated' => 1, 'invalidMarkup' => 0, 'missing' => 0, 'kept' => 0], $jobs[0]['counts']);
        self::assertSame([], $this->items->written);

        $jobs = $this->exchange()->import($xliff, true, false);
        self::assertSame(1, $jobs[0]['counts']['imported']);
        self::assertSame(['name' => 'Box'], $this->items->written[0][3]);
    }

    public function testInvalidMarkupMissingAndKept(): void
    {
        $html = $this->exchange()->export('product', [42], [3], 'html', false)['content'];
        $html = str_replace('Handmade ', 'Handmade <em>x</em> ', $html);
        $this->items->source['meta_title'] = 'Praline box | Shop';
        $this->items->target[3]['name'] = 'Boîte de pralines';

        $jobs = $this->exchange()->import($html, false, false);
        self::assertSame(['imported' => 0, 'sourceChanged' => 0, 'untranslated' => 0, 'invalidMarkup' => 1, 'missing' => 1, 'kept' => 1], $jobs[0]['counts']);
        self::assertSame([], $this->items->written);
    }

    public function testJobErrors(): void
    {
        $file  = static fn (string $plugin, string $reference): string => TranslationFiles::exportXliff([new FileJob($plugin, $reference, 'en', 'de-CH', [new FileSegment('name', 'Praline box')])]);
        $error = fn (string $content): ?string => $this->exchange()->import($content, false, false)[0]['error']?->getMessage();

        self::assertSame('This file belongs to another system (Pimcore-Supertext-Translation).', $error($file('Pimcore-Supertext-Translation', 'product:42:store-2')));
        self::assertSame('The item this file was exported from no longer exists.', $error($file(FileExchange::PLUGIN, 'page:12')));
        self::assertSame('The item this file was exported from no longer exists.', $error($file(FileExchange::PLUGIN, 'product:99:store-2')));
        self::assertSame('The shop has no store view 9.', $error($file(FileExchange::PLUGIN, 'product:42:store-9')));

        $this->items->allowed = false;
        self::assertSame('You are not allowed to edit this item.', $error($file(FileExchange::PLUGIN, 'product:42:store-2')));

        $this->items->allowed = true;
        $this->items->fail    = new \RuntimeException('URL key exists');
        $xliff = self::translate($file(FileExchange::PLUGIN, 'product:42:store-3'), 'name', 'Box');
        self::assertSame('The translation could not be saved: URL key exists', $error($xliff));
        self::assertSame([], $this->items->written);
    }

    public function testFileErrors(): void
    {
        foreach ([
            'The file is too large (at most 10 MB).'                                         => str_repeat('a', FileExchange::MAX_UPLOAD_BYTES + 1),
            'This file isn\'t a valid XLIFF or HTML translation file.'                        => 'not a translation file',
            'This file wasn\'t exported by the Supertext module, or its header was removed.' => '<!doctype html><html><head></head><body><p data-st-id="1">x</p></body></html>',
        ] as $message => $content) {
            try {
                $this->exchange()->import($content, false, false);
                self::fail("Expected $message");
            } catch (SupertextException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        self::assertSame(10_000_000, FileExchange::MAX_UPLOAD_BYTES);
    }

    private static function translate(string $xliff, string $key, string $target): string
    {
        return (string) preg_replace_callback(
            '~(<trans-unit id="' . preg_quote($key, '~') . '".*?</source>)~s',
            static fn (array $m): string => $m[1] . '<target>' . $target . '</target>',
            $xliff,
        );
    }
}

/** Shop stand-in: product 42, store views 2 (de-CH, name translated) and 3 (no Supertext language). */
final class FakeItems implements FileItems
{
    private const FIELDS = [
        'name'        => ['html' => false, 'size' => 255],
        'description' => ['html' => true, 'size' => 0],
        'meta_title'  => ['html' => false, 'size' => 255],
    ];

    /** @var array<string, string> */
    public array $source = ['name' => 'Praline box', 'description' => '<p>Handmade <strong>{{store url=""}}</strong></p>', 'meta_title' => ''];

    /** @var array<int, array<string, string>> */
    public array $target = [2 => ['name' => 'Pralinenschachtel'], 3 => []];

    /** @var list<array{string, int, int, array<string, string>}> */
    public array $written = [];

    public bool $allowed = true;

    public ?\Throwable $fail = null;

    public function storeIds(): array
    {
        return [1, 2, 3];
    }

    public function sourceLanguage(): string
    {
        return 'en';
    }

    public function targetLanguage(int $storeId): string
    {
        return $storeId === 2 ? 'de-CH' : 'xx-YY';
    }

    public function allowed(string $type): bool
    {
        return $this->allowed;
    }

    public function segments(string $type, int $id, int $storeId, bool $overwrite): ?array
    {
        if ($id !== 42) {
            return null;
        }
        $planner  = $this->planner($storeId, $overwrite);
        $segments = [];
        foreach ($planner->segments() as $field => $segment) {
            $segments[$field] = ['text' => $segment->text, 'html' => $segment->html];
        }

        return ['segments' => $segments, 'kept' => $planner->kept()];
    }

    public function write(string $type, int $id, int $storeId, bool $overwrite, array $translations): array
    {
        if ($this->fail !== null) {
            throw $this->fail;
        }
        // As Translator::importTexts(): the planner applies the texts (directives unprotected).
        $this->written[] = [$type, $id, $storeId, $this->planner($storeId, $overwrite)->apply($translations)];

        return [];
    }

    private function planner(int $storeId, bool $overwrite): FieldPlanner
    {
        return new FieldPlanner(self::FIELDS, $this->source, $this->target[$storeId] ?? [], $overwrite);
    }
}
