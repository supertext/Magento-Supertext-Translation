<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Controller\Adminhtml\System;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Supertext\Translation\Model\Reporting;

/**
 * "Send configuration to Supertext" in the configuration: one `extensions` report with the installed
 * modules and each store view's Supertext language (Model\Reporting::sendConfiguration()). Nothing is
 * sent while version information is off.
 */
class Configuration extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Supertext_Translation::config';

    public function __construct(Context $context, private readonly Reporting $reporting)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Json $json */
        $json   = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result = $this->reporting->sendConfiguration();

        [$ok, $text] = match (true) {
            $result === null => [false, (string) __('Turn on "Send version information to Supertext" and save the configuration to send it.')],
            $result['sent']  => [true, (string) __('Sent: %1 modules, %2 store views.', $result['extensions'], $result['languages'])],
            default          => [false, (string) __('The configuration couldn\'t be sent. Try again later.')],
        };

        return $json->setData(['ok' => $ok, 'message' => $text, 'html' => htmlspecialchars($text, ENT_QUOTES)]);
    }
}
