<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Supertext\Translation\Api\LanguageCodes;

/**
 * The store view's Supertext language: empty (the default for its locale) or one of Supertext's
 * target codes, stored as Supertext lists it ("de_ch" → "de-CH"). A code saved by an earlier version
 * that isn't in the list is kept as long as it isn't changed (it is sent as it is).
 */
class LanguageCode extends Value
{
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());

        if ($value !== '') {
            $canonical = LanguageCodes::canonical($value);

            if ($canonical === null && $value !== trim((string) $this->getOldValue())) {
                throw new LocalizedException(__('"%1" is not one of the Supertext languages. Choose one from the Supertext language list.', $value));
            }

            $value = $canonical ?? $value;
        }

        $this->setValue($value);

        return parent::beforeSave();
    }
}
