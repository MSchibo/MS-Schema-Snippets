<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

interface QueueInterface
{
    public function addOrUpdate(int $pageUid, string $mode, string $json, string $contentHash, string $reason = ''): void;

    /** @return array<int, array<string,mixed>> */
    public function list(string|array $status = 'pending'): array;

    /** @return array<string,mixed>|null */
    public function get(int $uid): ?array;

    public function setStatus(int $uid, string $status, string $error = ''): void;

    /** @return array<int, array<string,mixed>> */
    public function popApproved(int $limit = 20): array;

    /** @return array<int, array<string,mixed>> */
    public function popPending(int $limit = 20): array;
}
