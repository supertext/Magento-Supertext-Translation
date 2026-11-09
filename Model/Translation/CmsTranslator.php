<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Model\Translation;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\StoreManagerInterface;
use Supertext\Sdk\V1\Client;
use Supertext\Translation\Api\SupertextException;
use Supertext\Translation\Model\Config;

/**
 * CMS pages and blocks have no store-view values: the translation for a store view is a
 * copy with the same identifier, assigned to that store view, linked to the original in
 * the table supertext_translation_link.
 *
 * Pages: Magento allows one page per URL and store view, so the original is first removed
 * from the target store view (an original on "All Store Views" is set to all other store
 * views). Blocks: a store-view block wins over an "All Store Views" block with the same
 * identifier, so the original stays as it is.
 */
class CmsTranslator
{
    private const TABLE = 'supertext_translation_link';

    /** Copied from the original when a translation is created; the translated fields come from Supertext. */
    private const SKIP_ON_COPY = ['page_id', 'block_id', 'row_id', 'creation_time', 'update_time', 'store_id', 'stores', '_first_store_id', 'store_code'];

    public function __construct(
        private readonly Config $config,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly PageFactory $pageFactory,
        private readonly BlockFactory $blockFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
    ) {
    }

    public function exists(string $type, int $id): bool
    {
        try {
            $this->load($type, $id);

            return true;
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    public function title(string $type, int $id): string
    {
        return (string) $this->load($type, $id)->getData('title');
    }

    /** The original's store views, or "all" ([0]). @return list<int> */
    public function stores(string $type, int $id): array
    {
        return array_map('intval', (array) $this->load($type, $id)->getStores());
    }

    /**
     * @param list<int> $storeIds
     *
     * @return array<int, string> store id => "none", "partial" or "translated"
     */
    public function states(string $type, int $id, array $storeIds): array
    {
        $fields = EntityTypes::get($type)['fields'];
        $source = $this->load($type, $id);
        $states = [];

        foreach ($storeIds as $storeId) {
            $target           = $this->target($type, $source, $storeId);
            $states[$storeId] = $target === null
                ? 'none'
                : (new FieldPlanner($fields, $this->values($source, $fields), $this->values($target, $fields), false))->state();
        }

        return $states;
    }

    /**
     * @return array{status: string, title: string, translated: list<string>, kept: list<string>, notes: list<string>, targetId?: int}
     *
     * @param Client|\Closure(array<string, \Supertext\Sdk\V1\Segment>): array<string, string> $client the texts of a translation file instead of Supertext
     *
     * @throws SupertextException|\Supertext\Sdk\V1\SupertextException|LocalizedException on failure (Translator turns SDK errors into messages)
     */
    public function translate(Client|\Closure $client, string $type, int $id, int $storeId, bool $overwrite): array
    {
        $fields = EntityTypes::get($type)['fields'];
        $source = $this->load($type, $id);
        $stores = array_map('intval', (array) $source->getStores());
        $title  = (string) $source->getData('title');
        $notes  = [];

        if ($stores === [$storeId]) {
            throw new SupertextException('The original is assigned only to this store view. Assign it to the store views of its own language first.');
        }

        $target  = $this->target($type, $source, $storeId);
        $planner = new FieldPlanner($fields, $this->values($source, $fields), $target === null ? [] : $this->values($target, $fields), $overwrite);

        if ($planner->toTranslate() === []) {
            return ['status' => 'unchanged', 'title' => $title, 'translated' => [], 'kept' => $planner->kept(), 'notes' => []];
        }

        // A translation file brings its own texts (Translator::importTexts()); otherwise Supertext translates.
        $values = $planner->apply($client instanceof \Closure ? $client($planner->segments()) : $client->translateTexts(
            $planner->segments(),
            $this->config->targetCode($storeId),
            $this->config->sourceCode(),
            $this->config->tone($storeId),
        ));

        $created = $target === null;

        if ($created) {
            $target = $type === 'cms_page' ? $this->pageFactory->create() : $this->blockFactory->create();

            foreach ($source->getData() as $key => $value) {
                if (!\in_array($key, self::SKIP_ON_COPY, true) && !\is_object($value)) {
                    $target->setData($key, $value);
                }
            }

            if ($this->config->cmsStatus() === 'disabled') {
                $target->setData('is_active', 0);
            }
        }

        foreach ($values as $field => $value) {
            $target->setData($field, $value);
        }

        $target->setData('store_id', [$storeId]);
        $target->setData('stores', [$storeId]);

        // A page URL belongs to one page per store view: take the store view away from the original first.
        $restore = null;

        if ($type === 'cms_page' && $this->narrow($stores, $storeId) !== $stores) {
            $restore = $stores;
            $this->saveStores($source, $this->narrow($stores, $storeId));
            $notes[] = (string) __('The original page is no longer shown in this store view; the translation replaces it there.');
        }

        try {
            $this->save($type, $target);
        } catch (\Throwable $e) {
            if ($restore !== null) {
                $this->saveStores($source, $restore);
            }

            throw new SupertextException('The translation could not be saved: %1', [$e->getMessage()], 0, $e);
        }

        $this->link($type, (int) $source->getId(), $storeId, (int) $target->getId());

        if ($created && $this->config->cmsStatus() === 'disabled') {
            $notes[] = (string) __('Created disabled: enable it after review.');
        }

        return [
            'status'     => $created ? 'created' : 'translated',
            'title'      => $title,
            'translated' => array_keys($values),
            'kept'       => $planner->kept(),
            'notes'      => $notes,
            'targetId'   => (int) $target->getId(),
        ];
    }

    /** What translate() would send and keep, for a translation file. */
    public function planner(string $type, int $id, int $storeId, bool $overwrite): FieldPlanner
    {
        $fields = EntityTypes::get($type)['fields'];
        $source = $this->load($type, $id);
        $target = $this->target($type, $source, $storeId);

        return new FieldPlanner($fields, $this->values($source, $fields), $target === null ? [] : $this->values($target, $fields), $overwrite);
    }

    /** The existing translation: linked, or a page/block with the same identifier assigned only to that store view. */
    public function target(string $type, AbstractModel $source, int $storeId): ?AbstractModel
    {
        $connection = $this->resource->getConnection();
        $targetId   = (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), 'target_id')
                ->where('entity_type = ?', $type)
                ->where('source_id = ?', (int) $source->getId())
                ->where('store_id = ?', $storeId)
        );

        if ($targetId > 0) {
            try {
                return $this->load($type, $targetId);
            } catch (NoSuchEntityException) {
                $connection->delete($this->resource->getTableName(self::TABLE), ['entity_type = ?' => $type, 'target_id = ?' => $targetId]);
            }
        }

        // Made by hand before the module was used: adopt it.
        $idField   = $type === 'cms_page' ? 'page_id' : 'block_id';
        $table     = $this->resource->getTableName($type);
        $linkTable = $this->resource->getTableName($type . '_store');
        $link      = $connection->tableColumnExists($table, 'row_id') ? 'row_id' : $idField;
        $found     = (int) $connection->fetchOne(
            $connection->select()
                ->from(['e' => $table], 'e.' . $idField)
                ->join(['s' => $linkTable], 's.' . $link . ' = e.' . $link, [])
                ->where('e.identifier = ?', (string) $source->getData('identifier'))
                ->where('e.' . $idField . ' <> ?', (int) $source->getId())
                ->group('e.' . $idField)
                ->having('COUNT(*) = 1 AND MAX(s.store_id) = ?', $storeId)
        );

        return $found > 0 ? $this->load($type, $found) : null;
    }

    /** @return Page|Block */
    private function load(string $type, int $id): AbstractModel
    {
        /** @var Page|Block $item */
        $item = $type === 'cms_page' ? $this->pageRepository->getById($id) : $this->blockRepository->getById((string) $id);

        return $item;
    }

    private function save(string $type, AbstractModel $item): void
    {
        $type === 'cms_page' ? $this->pageRepository->save($item) : $this->blockRepository->save($item);
    }

    private function saveStores(AbstractModel $item, array $stores): void
    {
        $item->setData('store_id', $stores);
        $item->setData('stores', $stores);
        $this->save($item instanceof Page ? 'cms_page' : 'cms_block', $item);
    }

    /**
     * @param list<int> $stores the original's store views ([0] = all)
     *
     * @return list<int>
     */
    private function narrow(array $stores, int $storeId): array
    {
        if (\in_array(0, $stores, true)) {
            $stores = array_map(static fn ($store): int => (int) $store->getId(), $this->storeManager->getStores(false));
        }

        return array_values(array_filter($stores, static fn (int $id): bool => $id !== $storeId));
    }

    /** @param array<string, array{html: bool, size: int}> $fields @return array<string, string> */
    private function values(AbstractModel $item, array $fields): array
    {
        $out = [];

        foreach (array_keys($fields) as $field) {
            $out[$field] = (string) $item->getData($field);
        }

        return $out;
    }

    private function link(string $type, int $sourceId, int $storeId, int $targetId): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            ['entity_type' => $type, 'source_id' => $sourceId, 'store_id' => $storeId, 'target_id' => $targetId],
            ['target_id']
        );
    }
}
