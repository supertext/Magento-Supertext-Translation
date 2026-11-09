<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Locale\ResolverInterface;
use Supertext\Translation\Api\LanguageCodes;
use Supertext\Translation\Model\Config;

/**
 * The store view's *Supertext language*: Supertext's list, plus a code saved before that isn't in
 * it (kept and sent as it is). In store view scope the empty option names the default for the store
 * view's locale ("Default: German (Germany) (de-DE)"), or asks to choose one when the locale has none
 * ("en_IE"), and below it the page says what the store view is translated into.
 */
class LanguageSelect extends Field
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ResolverInterface $localeResolver,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $value = trim((string) $element->getValue());
        /** @var list<array{value: string, label: mixed}> $values */
        $values = (array) $element->getValues();

        if ($value !== '' && !\in_array($value, array_column($values, 'value'), true)) {
            $values[] = ['value' => $value, 'label' => $value];
        }

        $storeId = (int) $this->getRequest()->getParam('store');

        if ($storeId > 0) {
            $locale  = $this->config->locale($storeId);
            $suggest = LanguageCodes::suggest($locale);
            $display = (string) $this->localeResolver->getLocale();

            foreach ($values as $i => $option) {
                if ((string) $option['value'] === '') {
                    $values[$i]['label'] = $suggest === null
                        ? __('– Choose –')
                        : __('Default: %1', LanguageCodes::displayName($suggest, $display) . ' (' . $suggest . ')');
                }
            }

            $target = $this->config->targetCodeOrNull($storeId);

            if ($target === null) {
                $note = '<strong style="color:#e22626">' . $this->escapeHtml(__('Not mapped: Supertext has no default for the locale %1. Choose its Supertext language, or nothing is translated into this store view.', $locale)) . '</strong>';
            } elseif ($value === '' && !LanguageCodes::isExactMatch($locale)) {
                $note = $this->escapeHtml(__('This store view is translated into %1, the default for the locale %2.', $target, $locale));
            } else {
                $note = $this->escapeHtml(__('This store view is translated into %1 (locale %2).', $target, $locale));
            }

            $comment = (string) $element->getComment();
            $element->setComment($comment !== '' ? $comment . '<br>' . $note : $note);
        }

        $element->setValues($values);

        return parent::_getElementHtml($element);
    }
}
