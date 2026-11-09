<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Model\Translation;

/**
 * What translation files need from Magento: store views, items, the texts an API translation
 * would send and the same write-back. TranslationFileItems is the Magento implementation; the
 * unit tests use a fake. No Magento classes here.
 */
interface FileItems
{
    /** @return list<int> the store view ids (not 0) */
    public function storeIds(): array;

    /** The Supertext code of the default values' language (the default locale's language if unmapped). */
    public function sourceLanguage(): string;

    /** The store view's Supertext code (its locale with "-" if it has none, so the export never blocks). */
    public function targetLanguage(int $storeId): string;

    /** Whether the admin user may edit items of this type (the type's ACL resource). */
    public function allowed(string $type): bool;

    /**
     * The texts an API translation into the store view would send (FieldPlanner::segments(), field
     * => text/html) and the fields that keep their translation, or null when the item doesn't exist.
     *
     * @return array{segments: array<string, array{text: string, html: bool}>, kept: list<string>}|null
     */
    public function segments(string $type, int $id, int $storeId, bool $overwrite): ?array;

    /**
     * Writes translated texts (field => text, as returned for segments()) through the API
     * translation's write-back (store-view values, CMS copies, URL keys).
     *
     * @param array<string, string> $translations
     *
     * @return list<string> notes (e.g. a URL key that was kept)
     *
     * @throws \Throwable when the item can't be saved
     */
    public function write(string $type, int $id, int $storeId, bool $overwrite, array $translations): array;
}
