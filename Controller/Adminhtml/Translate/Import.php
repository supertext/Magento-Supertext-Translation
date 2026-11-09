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
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Supertext\Translation\Api\SupertextException;
use Supertext\Translation\Model\Translation\EntityTypes;
use Supertext\Translation\Model\Translation\FileExchange;
use Supertext\Translation\Model\Translation\TranslationFileItems;

/**
 * "Import translation": POST (multipart) file, include_changed, overwrite → JSON summary per item
 * and store view (called by the translate page). The upload is read in memory and never stored;
 * each item needs the ACL of its content type.
 */
class Import extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Supertext_Translation::translate';

    public function __construct(
        Context $context,
        private readonly TranslationFileItems $items,
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $storeManager,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Json $json */
        $json    = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $request = $this->getRequest();
        $file    = $request instanceof HttpRequest ? $request->getFiles('file') : null;
        $file    = \is_array($file) ? $file : [];
        $error   = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE || !isset($file['tmp_name'])) {
            return $json->setData(['ok' => false, 'message' => (string) __('Choose a file to import.')]);
        }

        if (\in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($file['size'] ?? 0) > FileExchange::MAX_UPLOAD_BYTES) {
            return $json->setData(['ok' => false, 'message' => (string) __('The file is too large (at most 10 MB).')]);
        }

        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return $json->setData(['ok' => false, 'message' => (string) __('This file isn\'t a valid XLIFF or HTML translation file.')]);
        }

        $content = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, FileExchange::MAX_UPLOAD_BYTES + 1);

        try {
            $jobs = (new FileExchange($this->items))->import($content, (bool) $request->getParam('include_changed'), (bool) $request->getParam('overwrite'));
        } catch (SupertextException $e) {
            return $json->setData(['ok' => false, 'message' => self::message($e)]);
        }

        $out = [];

        foreach ($jobs as $job) {
            if ($job['error'] !== null) {
                $this->logger->warning('Supertext: translation file ' . $job['reference'] . ': ' . $job['error']->getMessage());
            }

            $edit  = $job['type'] !== '' ? EntityTypes::get($job['type'])['edit'] : null;
            $out[] = [
                'title'   => $job['type'] !== '' ? (string) __(EntityTypes::get($job['type'])['label']) . ' #' . $job['id'] . ' → ' . $this->storeName($job['store']) : $job['reference'],
                'editUrl' => $edit !== null && $job['error'] === null ? $this->getUrl($edit[0], [$edit[1] => $job['id'], 'store' => $job['store']]) : null,
                'error'   => $job['error'] !== null ? self::message($job['error']) : null,
                'lines'   => $job['error'] === null ? self::lines($job['counts']) : [],
                'notes'   => $job['notes'],
                'ok'      => $job['error'] === null,
            ];
        }

        return $json->setData(['ok' => true, 'jobs' => $out]);
    }

    /** @param array<string, int> $counts @return list<string> */
    private static function lines(array $counts): array
    {
        $lines = [(string) __('Imported: %1', $counts['imported'])];

        foreach ([
            'sourceChanged' => __('Skipped, source changed since the export: %1', $counts['sourceChanged']),
            'untranslated'  => __('Not translated in the file: %1', $counts['untranslated']),
            'invalidMarkup' => __('Formatting changed, not imported: %1', $counts['invalidMarkup']),
            'missing'       => __('Not in the file: %1', $counts['missing']),
            'kept'          => __('Kept, already translated in this store view: %1', $counts['kept']),
        ] as $count => $text) {
            if ($counts[$count] > 0) {
                $lines[] = (string) $text;
            }
        }

        return $lines;
    }

    private static function message(SupertextException $e): string
    {
        return SupertextException::withDetail((string) __($e->template(), ...$e->parameters()), $e->detail());
    }

    private function storeName(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getName();
        } catch (\Throwable) {
            return '#' . $storeId;
        }
    }
}
