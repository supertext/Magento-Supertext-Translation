<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Supertext\Translation\Model\Config;
use Supertext\Translation\Model\Translation\EntityTypes;
use Supertext\Translation\Model\Translation\Translator;

/** Data for templates/translate.phtml. */
class Translate extends Template
{
    protected $_template = 'Supertext_Translation::translate.phtml';

    /** @var list<array{id: int, title: string, editUrl: string, states: array<int, string>}>|null */
    private ?array $items = null;

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Translator $translator,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getType(): string
    {
        $type = (string) $this->getRequest()->getParam('type', 'product');

        return isset(EntityTypes::TYPES[$type]) ? $type : 'product';
    }

    public function getTypeLabel(): string
    {
        return (string) __(EntityTypes::get($this->getType())['label']);
    }

    public function isCms(): bool
    {
        return EntityTypes::get($this->getType())['kind'] === 'cms';
    }

    public function canEdit(): bool
    {
        return $this->_authorization->isAllowed(EntityTypes::get($this->getType())['acl']);
    }

    /** @return list<array{id: int, name: string, code: string, website: string, locale: string, setting: string, target: ?string, active: bool, sameLanguage: bool, tone: string, toneIgnored: bool}> */
    public function getStores(): array
    {
        return $this->config->storeViews();
    }

    public function getSourceLocale(): string
    {
        return $this->config->locale(0);
    }

    /** @return list<array{id: int, title: string, editUrl: string, states: array<int, string>}> */
    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $type     = $this->getType();
        $edit     = EntityTypes::get($type)['edit'];
        $storeIds = array_column($this->getStores(), 'id');
        $ids      = \array_slice(EntityTypes::ids($this->getRequest()->getParam('ids', $this->getRequest()->getParam('id', ''))), 0, 100);

        $this->items = [];

        foreach ($ids as $id) {
            if (!$this->translator->exists($type, $id)) {
                continue;
            }

            $this->items[] = [
                'id'      => $id,
                'title'   => $this->translator->title($type, $id) ?: '#' . $id,
                'editUrl' => $this->getUrl($edit[0], [$edit[1] => $id]),
                'states'  => $this->translator->states($type, $id, $storeIds),
            ];
        }

        return $this->items;
    }

    public function hasApiKey(): bool
    {
        return $this->config->apiKey() !== '';
    }

    public function getRunUrl(): string
    {
        return $this->getUrl('supertext/translate/run');
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('supertext/translate/export');
    }

    public function getImportUrl(): string
    {
        return $this->getUrl('supertext/translate/import');
    }

    /** The ids of the listed items, comma-separated (for the export form). */
    public function getIdsParam(): string
    {
        return implode(',', array_column($this->getItems(), 'id'));
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'supertext']);
    }

    /** Stores → Configuration → Supertext → Translation in a store view's scope (its Supertext language). */
    public function getStoreConfigUrl(int $storeId): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'supertext', 'store' => $storeId]);
    }

    public function canConfigure(): bool
    {
        return $this->_authorization->isAllowed('Supertext_Translation::config');
    }

    public function getStoresUrl(): string
    {
        return $this->getUrl('adminhtml/system_store/');
    }

    public function getSignupUrl(): string
    {
        return Config::SIGNUP_URL;
    }

    public function getApiKeyUrl(): string
    {
        return Config::API_KEY_URL;
    }

    public function getListUrl(): string
    {
        return $this->getUrl(match ($this->getType()) {
            'category'  => 'catalog/category/',
            'cms_page'  => 'cms/page/',
            'cms_block' => 'cms/block/',
            default     => 'catalog/product/',
        });
    }
}
