<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DbQueueAdapter implements QueueInterface
{
    private QueueService $queue;

    public function __construct()
    {
        $this->queue = GeneralUtility::makeInstance(QueueService::class);
    }

    public function addOrUpdate(int $pageUid, string $mode, string $json, string $contentHash, string $reason = ''): void
    {
        $this->queue->addOrUpdate($pageUid, $mode, $json, $contentHash, $reason);
    }

    public function list(string|array $status = 'pending'): array
    {
        // ❗ NICHT casten – sonst wird aus Array -> "Array"
        return $this->queue->list($status);
    }

    public function get(int $uid): ?array
    {
        return $this->queue->get($uid);
    }

    public function setStatus(int $uid, string $status, string $error = ''): void
    {
        $this->queue->setStatus($uid, $status, $error);
    }

    public function popPending(int $limit = 20): array
    {
        return $this->queue->popPending($limit);
    }

    public function popApproved(int $limit = 20): array
    {
        return $this->queue->popApproved($limit);
    }
}
