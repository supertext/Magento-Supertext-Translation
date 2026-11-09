<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Model\Translation;

use Supertext\Sdk\Files\FileJob;
use Supertext\Sdk\Files\FileSegment;
use Supertext\Sdk\Files\ImportedSegment;
use Supertext\Sdk\Files\TranslationFile;
use Supertext\Sdk\Files\TranslationFileError;
use Supertext\Sdk\Files\TranslationFiles;
use Supertext\Translation\Api\SupertextException;

/**
 * "Export for translation" and "Import translation": items' texts as an XLIFF 1.2 or HTML file
 * (the SDK's TranslationFiles), translated outside the API, and the translated file written back
 * with the API translation's rules (FieldPlanner: the same fields, existing translations kept
 * unless overwrite).
 *
 * Reference of a job: "<type>:<id>:store-<store view id>", e.g. "product:42:store-2" or
 * "cms_page:5:store-3" (ids, never titles). Segment keys are the attribute codes / CMS fields
 * (`name`, `description`, `content`, …).
 *
 * No Magento classes: the items are a FileItems.
 */
final class FileExchange
{
    /** The `plugin` of every job: the GitHub repository name. */
    public const PLUGIN = 'Magento-Supertext-Translation';

    public const SHORT_NAME = 'magento';

    public const XLIFF = 'xliff';
    public const HTML = 'html';

    /** Uploads larger than this are refused before they are read (10 MB, the SDK's limit). */
    public const MAX_UPLOAD_BYTES = TranslationFiles::MAX_FILE_CHARACTERS;

    /** Counters of an imported job. */
    public const COUNTS = ['imported', 'sourceChanged', 'untranslated', 'invalidMarkup', 'missing', 'kept'];

    public function __construct(private readonly FileItems $items)
    {
    }

    public static function reference(string $type, int $id, int $storeId): string
    {
        return $type . ':' . $id . ':store-' . $storeId;
    }

    /** @return array{type: string, id: int, store: int}|null */
    public static function parseReference(string $reference): ?array
    {
        if (preg_match('/^([a-z_]+):([1-9][0-9]{0,18}):store-([1-9][0-9]{0,9})$/', $reference, $m) !== 1 || !isset(EntityTypes::TYPES[$m[1]])) {
            return null;
        }

        return ['type' => $m[1], 'id' => (int) $m[2], 'store' => (int) $m[3]];
    }

    /**
     * "magento-product-42-store-2.xlf"; "magento-product-42.xlf" for several store views,
     * "magento-product-42-and-2-more.xlf" for several items.
     *
     * @param list<int> $ids
     * @param list<int> $storeIds
     */
    public static function fileName(string $type, array $ids, array $storeIds, string $format): string
    {
        $name = self::SHORT_NAME . '-' . $type . '-' . ($ids[0] ?? 0)
            . (\count($ids) > 1 ? '-and-' . (\count($ids) - 1) . '-more' : '')
            . (\count($storeIds) === 1 ? '-store-' . $storeIds[0] : '');

        return (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . ($format === self::HTML ? '.html' : '.xlf');
    }

    public static function contentType(string $format): string
    {
        return $format === self::HTML ? 'text/html; charset=utf-8' : 'application/x-xliff+xml';
    }

    /**
     * @param list<int> $ids
     * @param list<int> $storeIds
     *
     * @return array{content: string, fileName: string, contentType: string, jobs: int}
     *
     * @throws SupertextException
     */
    public function export(string $type, array $ids, array $storeIds, string $format, bool $overwrite): array
    {
        $format   = $format === self::HTML ? self::HTML : self::XLIFF;
        $ids      = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        $storeIds = array_values(array_intersect(array_unique($storeIds), $this->items->storeIds()));

        if (!isset(EntityTypes::TYPES[$type]) || $ids === []) {
            throw new SupertextException('Nothing selected. Go back to the list and select the items to translate.');
        }

        if ($storeIds === []) {
            throw new SupertextException('Choose at least one store view to translate into.');
        }

        if ($format === self::HTML && (\count($storeIds) > 1 || \count($ids) > 1)) {
            throw new SupertextException('An HTML file holds one item in one language: choose exactly one store view, or use XLIFF.');
        }

        $jobs    = [];
        $usedIds = [];
        $usedStores = [];

        foreach ($ids as $id) {
            foreach ($storeIds as $storeId) {
                $item = $this->items->segments($type, $id, $storeId, $overwrite);

                if ($item === null) {
                    throw new SupertextException('%1 %2 does not exist.', [ucfirst($type), $id]);
                }

                $segments = [];

                foreach ($item['segments'] as $field => $segment) {
                    $segments[] = new FileSegment((string) $field, $segment['text'], $segment['html']);
                }

                if ($segments === []) {
                    continue;
                }

                $jobs[]       = new FileJob(self::PLUGIN, self::reference($type, $id, $storeId), $this->items->sourceLanguage(), $this->items->targetLanguage($storeId), $segments);
                $usedIds[$id] = $id;
                $usedStores[$storeId] = $storeId;
            }
        }

        if ($jobs === []) {
            throw new SupertextException('Nothing to translate.');
        }

        try {
            $content = $format === self::HTML ? TranslationFiles::exportHtml($jobs[0]) : TranslationFiles::exportXliff($jobs);
        } catch (TranslationFileError $e) {
            throw new SupertextException('The file could not be created.', [], 0, $e, $e->getMessage());
        }

        return [
            'content'     => $content,
            'fileName'    => self::fileName($type, array_values($usedIds), array_values($usedStores), $format),
            'contentType' => self::contentType($format),
            'jobs'        => \count($jobs),
        ];
    }

    /**
     * @return list<array{reference: string, type: string, id: int, store: int, error: SupertextException|null, counts: array<string, int>, notes: list<string>}>
     *
     * @throws SupertextException a file that is too large, invalid or without metadata
     */
    public function import(string $content, bool $includeChanged, bool $overwrite): array
    {
        if (\strlen($content) > self::MAX_UPLOAD_BYTES) {
            throw new SupertextException('The file is too large (at most 10 MB).');
        }

        try {
            $files = TranslationFiles::read($content);
        } catch (TranslationFileError $e) {
            throw self::fileError($e);
        }

        $results = [];

        foreach ($files as $file) {
            $results[] = $this->importJob($file, $includeChanged, $overwrite);
        }

        return $results;
    }

    /**
     * @return array{reference: string, type: string, id: int, store: int, error: SupertextException|null, counts: array<string, int>, notes: list<string>}
     */
    private function importJob(TranslationFile $file, bool $includeChanged, bool $overwrite): array
    {
        $result = ['reference' => $file->reference, 'type' => '', 'id' => 0, 'store' => 0, 'error' => null, 'counts' => array_fill_keys(self::COUNTS, 0), 'notes' => []];

        if ($file->plugin !== self::PLUGIN) {
            return ['error' => new SupertextException('This file belongs to another system (%1).', [$file->plugin])] + $result;
        }

        $reference = self::parseReference($file->reference);

        if ($reference === null) {
            return ['error' => new SupertextException('The item this file was exported from no longer exists.')] + $result;
        }

        $result = ['type' => $reference['type'], 'id' => $reference['id'], 'store' => $reference['store']] + $result;

        if (!\in_array($reference['store'], $this->items->storeIds(), true)) {
            return ['error' => new SupertextException('The shop has no store view %1.', [$reference['store']])] + $result;
        }

        if (!$this->items->allowed($reference['type'])) {
            return ['error' => new SupertextException('You are not allowed to edit this item.')] + $result;
        }

        $item = $this->items->segments($reference['type'], $reference['id'], $reference['store'], $overwrite);

        if ($item === null) {
            return ['error' => new SupertextException('The item this file was exported from no longer exists.')] + $result;
        }

        $counts         = $result['counts'];
        $fileKeys       = array_map(static fn ($unit): string => $unit->key, $file->units);
        $counts['kept'] = \count(array_intersect($fileKeys, $item['kept']));
        $current        = [];

        foreach ($item['segments'] as $field => $segment) {
            $current[] = new FileSegment((string) $field, $segment['text'], $segment['html']);
        }

        try {
            $imported = TranslationFiles::importTranslations($file, $current);
        } catch (TranslationFileError $e) {
            return ['error' => self::fileError($e)] + $result;
        }

        $status = [
            ImportedSegment::SOURCE_CHANGED => 'sourceChanged',
            ImportedSegment::UNTRANSLATED   => 'untranslated',
            ImportedSegment::INVALID_MARKUP => 'invalidMarkup',
            ImportedSegment::MISSING        => 'missing',
        ];
        $translations = [];

        foreach ($imported as $segment) {
            $write = $segment->status === ImportedSegment::OK || ($segment->status === ImportedSegment::SOURCE_CHANGED && $includeChanged);

            if ($write && $segment->text !== null && trim(strip_tags($segment->text)) !== '') {
                $translations[$segment->key] = $segment->text;
            } else {
                $counts[$status[$segment->status] ?? 'untranslated']++;
            }
        }

        if ($translations !== []) {
            try {
                $result['notes'] = $this->items->write($reference['type'], $reference['id'], $reference['store'], $overwrite, $translations);
            } catch (SupertextException $e) {
                return ['counts' => $counts, 'error' => $e] + $result;
            } catch (\Throwable $e) {
                return ['counts' => $counts, 'error' => new SupertextException('The translation could not be saved: %1', [$e->getMessage()], 0, $e)] + $result;
            }
        }

        $counts['imported'] = \count($translations);

        return ['counts' => $counts] + $result;
    }

    private static function fileError(TranslationFileError $e): SupertextException
    {
        return match ($e->errorCode) {
            TranslationFileError::TOO_LARGE        => new SupertextException('The file is too large (at most 10 MB).', [], 0, $e),
            TranslationFileError::MISSING_METADATA => new SupertextException('This file wasn\'t exported by the Supertext module, or its header was removed.', [], 0, $e),
            default                                => new SupertextException('This file isn\'t a valid XLIFF or HTML translation file.', [], 0, $e),
        };
    }
}
