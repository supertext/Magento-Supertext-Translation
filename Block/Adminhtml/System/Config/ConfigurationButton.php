<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** "Send configuration to Supertext": the installed modules and the language mapping, on demand. */
class ConfigurationButton extends Field
{
    protected $_template = 'Supertext_Translation::system/configuration-button.phtml';

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('supertext/system/configuration');
    }
}
