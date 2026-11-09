<?php

/**
 * @package     Supertext Translation for Magento
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace Supertext\Translation\Controller\Adminhtml\Translate;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Supertext\Translation\Api\SupertextException;
use Supertext\Translation\Model\Translation\EntityTypes;
use Supertext\Translation\Model\Translation\FileExchange;
use Supertext\Translation\Model\Translation\TranslationFileItems;

/**
 * "Export for translation": POST type, ids, stores[], format (xliff|html), overwrite → the XLIFF 1.2
 * or HTML file as a download (called by the translate page's form). Nothing is stored.
 */
class Export extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Supertext_Translation::translate';

    public function __construct(Context $context, private readonly TranslationFileItems $items)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $type    = (string) $request->getParam('type');
        $back    = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('supertext/translate/index', ['type' => $type, 'ids' => (string) $request->getParam('ids')]);

        if (!isset(EntityTypes::TYPES[$type]) || !$this->items->allowed($type)) {
            $this->messageManager->addErrorMessage(__('You are not allowed to edit this item.'));

            return $back;
        }

        try {
            $file = (new FileExchange($this->items))->export(
                $type,
                EntityTypes::ids($request->getParam('ids')),
                array_map('intval', (array) $request->getParam('stores', [])),
                (string) $request->getParam('format'),
                (bool) $request->getParam('overwrite'),
            );
        } catch (SupertextException $e) {
            $this->messageManager->addErrorMessage(SupertextException::withDetail((string) __($e->template(), ...$e->parameters()), $e->detail()));

            return $back;
        }

        /** @var Raw $raw */
        $raw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $raw->setHeader('Content-Type', $file['contentType'], true)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $file['fileName'] . '"', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setContents($file['content']);

        return $raw;
    }
}
