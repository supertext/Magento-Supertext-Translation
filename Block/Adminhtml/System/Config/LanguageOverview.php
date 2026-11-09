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
use Supertext\Translation\Api\Supertext;
use Supertext\Translation\Model\Config;

/**
 * Default scope: every store view with its locale and the Supertext language it is translated into,
 * (from the setting, from the locale or the default for the locale), or *Not mapped* (no default for
 * the locale) with a link to its store view scope, *Translation review* where it is on, and a note
 * where the tone isn't offered.
 */
class LanguageOverview extends Field
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ResolverInterface $localeResolver,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        try {
            $views  = $this->config->storeViews();
            $source = $this->config->sourceCodeOrNull();
            $review = Supertext::reviewBaseUrl($this->config->reviewUrl(), $this->config->baseUrl()) !== null;
        } catch (\Throwable) {
            return '';
        }

        $rows = '';

        foreach ($views as $view) {
            $link = $this->getUrl('adminhtml/system_config/edit', ['section' => 'supertext', 'store' => $view['id']]);

            if ($view['target'] === null) {
                $language = '<strong style="color:#e22626">' . $this->escapeHtml(__('Not mapped')) . '</strong> · '
                    . '<a href="' . $this->escapeUrl($link) . '">' . $this->escapeHtml(__('Choose its Supertext language')) . '</a>';
            } else {
                $name     = LanguageCodes::displayName($view['target'], (string) $this->localeResolver->getLocale());
                $language = '<code>' . $this->escapeHtml($view['target']) . '</code>' . ($name !== '' ? ' ' . $this->escapeHtml($name) : '')
                    . match ($view['origin']) {
                        'locale'  => ' <span style="color:#6c868e">(' . $this->escapeHtml(__('from the locale')) . ')</span>',
                        'default' => ' <span style="color:#6c868e">(' . $this->escapeHtml(__('default for the locale')) . ')</span>',
                        default   => '',
                    }
                    . ($view['sameLanguage'] ? ' <span style="color:#6c868e">· ' . $this->escapeHtml(__('Source language')) . '</span>' : '')
                    . ($view['review'] && $review ? ' <span style="color:#6c868e">· ' . $this->escapeHtml(__('Translation review')) . '</span>' : '');
            }

            if ($view['toneIgnored']) {
                $language .= '<br><span style="color:#a35c00">' . $this->escapeHtml(__('Supertext offers no formal or informal tone for this language: the Supertext default is used.')) . '</span>';
            }

            $rows .= '<tr><td><a href="' . $this->escapeUrl($link) . '">' . $this->escapeHtml($view['name']) . '</a></td>'
                . '<td><code>' . $this->escapeHtml($view['locale']) . '</code></td><td>' . $language . '</td></tr>';
        }

        $sourceNote = $source === null
            ? '<strong style="color:#e22626">' . $this->escapeHtml(__('The default locale %1 has no Supertext source language: choose the Supertext language of the default store view.', $this->config->locale(0))) . '</strong>'
            : $this->escapeHtml(__('Translations start from the default values in %1 (default locale %2).', $source, $this->config->locale(0)));

        return '<table class="data-grid" style="margin-bottom:8px"><thead><tr>'
            . '<th class="data-grid-th">' . $this->escapeHtml(__('Store view')) . '</th>'
            . '<th class="data-grid-th">' . $this->escapeHtml(__('Locale')) . '</th>'
            . '<th class="data-grid-th">' . $this->escapeHtml(__('Supertext language')) . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="note"><span>' . $sourceNote . '</span></p>';
    }

    protected function _isInheritCheckboxRequired(AbstractElement $element): bool
    {
        return false;
    }
}
