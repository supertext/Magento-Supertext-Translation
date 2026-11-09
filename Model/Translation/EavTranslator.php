<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Model\Translation;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Url as ProductUrl;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\AbstractResource;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\AbstractModel;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Psr\Log\LoggerInterface;
use Supertext\Sdk\V1\Client;
use Supertext\Translation\Api\SupertextException;
use Supertext\Translation\Model\Config;

/**
 * Products and categories: translates the default ("All Store Views") values of the
 * store-scoped text attributes into a store view's own values. The URL key follows the
 * translated name while the store view still uses the default one (or with overwrite),
 * and the store view's URL rewrites are regenerated.
 */
class EavTranslator
{
    public function __construct(
        private readonly Config $config,
        private readonly ProductFactory $productFactory,
        private readonly CategoryFactory $categoryFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ProductUrl $productUrl,
        private readonly ProductUrlRewriteGenerator $productUrlRewriteGenerator,
        private readonly CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        private readonly CategoryUrlPathGenerator $categoryUrlPathGenerator,
        private readonly UrlPersistInterface $urlPersist,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function exists(string $type, int $id): bool
    {
        $resource   = $this->resource($type);
        $connection = $resource->getConnection();

        return (bool) $connection->fetchOne(
            $connection->select()->from($resource->getEntityTable(), 'entity_id')->where('entity_id = ?', $id)
        );
    }

    public function title(string $type, int $id): string
    {
        return (string) $this->values($type, $id, 0, [EntityTypes::get($type)['title']])[EntityTypes::get($type)['title']];
    }

    /**
     * @param list<int> $storeIds
     *
     * @return array<int, string> store id => "none", "partial" or "translated"
     */
    public function states(string $type, int $id, array $storeIds): array
    {
        $fields = EntityTypes::get($type)['fields'];
        $source = $this->values($type, $id, 0, array_keys($fields));
        $states = [];

        foreach ($storeIds as $storeId) {
            $states[$storeId] = (new FieldPlanner($fields, $source, $this->values($type, $id, $storeId, array_keys($fields)), false))->state();
        }

        return $states;
    }

    /**
     * @return array{status: string, title: string, translated: list<string>, kept: list<string>, notes: list<string>, targetId?: int}
     *
     * @param Client|\Closure(array<string, \Supertext\Sdk\V1\Segment>): array<string, string> $client the texts of a translation file instead of Supertext
     *
     * @throws SupertextException|\Supertext\Sdk\V1\SupertextException on failure (Translator turns SDK errors into messages)
     */
    public function translate(Client|\Closure $client, string $type, int $id, int $storeId, bool $overwrite): array
    {
        $definition = EntityTypes::get($type);
        $fields     = $definition['fields'];
        $codes      = array_merge(array_keys($fields), ['url_key']);
        $source     = $this->values($type, $id, 0, $codes);
        $target     = $this->values($type, $id, $storeId, $codes);
        $planner    = new FieldPlanner($fields, $source, $target, $overwrite);
        $title      = (string) $source[$definition['title']];
        $notes      = [];

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

        $object = $this->load($type, $id, $storeId);

        foreach ($values as $code => $value) {
            $object->setData($code, $value);
            $object->getResource()->saveAttribute($object, $code);
        }

        $from = $definition['urlKey'];

        if ($from !== null && isset($values[$from]) && $planner->shouldRewriteSlug((string) $source['url_key'], (string) $target['url_key'])) {
            $note = $this->translateUrlKey($type, $id, $storeId, (string) $values[$from], (string) $target['url_key']);

            if ($note !== '') {
                $notes[] = $note;
            }
        }

        $this->refresh($type, $id);

        return ['status' => 'translated', 'title' => $title, 'translated' => array_keys($values), 'kept' => $planner->kept(), 'notes' => $notes];
    }

    /** What translate() would send and keep, for a translation file. */
    public function planner(string $type, int $id, int $storeId, bool $overwrite): FieldPlanner
    {
        $fields = EntityTypes::get($type)['fields'];

        return new FieldPlanner($fields, $this->values($type, $id, 0, array_keys($fields)), $this->values($type, $id, $storeId, array_keys($fields)), $overwrite);
    }

    /** Sets the store view's URL key from the translated name and regenerates its URL rewrites. Returns a note if it was kept. */
    private function translateUrlKey(string $type, int $id, int $storeId, string $translatedName, string $oldKey): string
    {
        $key = $this->productUrl->formatUrlKey($translatedName);

        if ($key === '' || $key === $oldKey) {
            return '';
        }

        $object = $this->load($type, $id, $storeId);
        $object->setData('url_key', $key);

        try {
            if ($type === 'category') {
                /** @var Category $object */
                $object->setData('url_path', $this->categoryUrlPathGenerator->getUrlPath($object));
                $object->getResource()->saveAttribute($object, 'url_key');
                $object->getResource()->saveAttribute($object, 'url_path');
                $category = $this->categoryRepository->get($id, $storeId);
                $this->urlPersist->replace($this->categoryUrlRewriteGenerator->generate($category));
            } else {
                $object->getResource()->saveAttribute($object, 'url_key');
                $product = $this->productRepository->getById($id, false, $storeId, true);
                $this->urlPersist->replace($this->productUrlRewriteGenerator->generate($product));
            }
        } catch (\Throwable $e) {
            // Usually: another item in the store view already uses this URL. Keep the old key.
            $object->setData('url_key', $oldKey);
            $object->getResource()->saveAttribute($object, 'url_key');

            if ($type === 'category') {
                $object->setData('url_path', $this->categoryUrlPathGenerator->getUrlPath($object));
                $object->getResource()->saveAttribute($object, 'url_path');
            }

            $this->logger->warning('Supertext: URL key "' . $key . '" not used for ' . $type . ' ' . $id . ' in store ' . $storeId . ': ' . $e->getMessage());

            return (string) __('URL key "%1" is already used in this store view; the URL was not changed.', $key);
        }

        return '';
    }

    /**
     * Store view values (falling back to the default value, as Magento shows them).
     *
     * @param list<string> $codes
     *
     * @return array<string, string>
     */
    private function values(string $type, int $id, int $storeId, array $codes): array
    {
        $raw = $this->resource($type)->getAttributeRawValue($id, $codes, $storeId);

        if (!\is_array($raw)) {
            $raw = \count($codes) === 1 ? [$codes[0] => $raw] : [];
        }

        $out = [];

        foreach ($codes as $code) {
            $out[$code] = (string) ($raw[$code] ?? '');
        }

        return $out;
    }

    /** @return Product|Category */
    private function load(string $type, int $id, int $storeId): AbstractModel
    {
        $object = $type === 'category' ? $this->categoryFactory->create() : $this->productFactory->create();
        $object->setStoreId($storeId);
        $object->getResource()->load($object, $id);

        if (!$object->getId()) {
            throw new SupertextException('%1 %2 does not exist.', [ucfirst($type), $id]);
        }

        return $object;
    }

    private function resource(string $type): AbstractResource
    {
        /** @var AbstractResource $resource */
        $resource = ($type === 'category' ? $this->categoryFactory->create() : $this->productFactory->create())->getResource();

        return $resource;
    }

    /** Search index and page caches, as Magento does after saving the item. */
    private function refresh(string $type, int $id): void
    {
        if ($type === 'product') {
            try {
                $indexer = $this->indexerRegistry->get('catalogsearch_fulltext');

                if (!$indexer->isScheduled()) {
                    $indexer->reindexRow($id);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Supertext: search reindex of product ' . $id . ' failed: ' . $e->getMessage());
            }
        }

        $this->cacheContext->registerEntities($type === 'category' ? Category::CACHE_TAG : Product::CACHE_TAG, [$id]);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
    }
}
