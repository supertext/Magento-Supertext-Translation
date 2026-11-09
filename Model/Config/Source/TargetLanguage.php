<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Locale\ResolverInterface;
use Supertext\Translation\Api\LanguageCodes;

/**
 * The *Supertext language* choices: Supertext's target codes (the SDK's targetOptions()) as
 * "Name (code)", the name in the admin's interface language where PHP's intl knows it. The empty
 * option means "the default for the store view's locale"; LanguageSelect names that default for the
 * store view shown, or says "Choose" when its locale has none.
 */
class TargetLanguage implements OptionSourceInterface
{
    public function __construct(private readonly ResolverInterface $localeResolver)
    {
    }

    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('Default for the locale')]];

        foreach (LanguageCodes::options((string) $this->localeResolver->getLocale()) as $option) {
            $options[] = $option;
        }

        return $options;
    }
}
