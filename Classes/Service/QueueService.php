<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class QueueService
{
    private const TABLE = 'tx_siters_queue';

    private function conn(): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE);
    }

    public function addOrUpdate(int $pageUid, string $mode, string $json, string $contentHash, string $reason = ''): void
    {
        $conn = $this->conn();
        $now  = time();

        $qb = $conn->createQueryBuilder();
        $row = $qb->select('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('page_uid', ':pageUid'),
                $qb->expr()->eq('content_hash', ':hash'),
                $qb->expr()->in('status', ':statuses')
            )
            ->setParameter('pageUid', $pageUid, ParameterType::INTEGER)
            ->setParameter('hash', $contentHash)
            ->setParameter('statuses', ['pending', 'approved'], Connection::PARAM_STR_ARRAY)
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (is_array($row) && isset($row['uid'])) {
            $conn->update(self::TABLE, [
                'snippet_json' => $json,
                'updated_at'   => $now,
                'reason'       => $reason,
                'mode'         => $mode,
            ], [
                'uid' => (int)$row['uid'],
            ]);
            return;
        }

        $conn->insert(self::TABLE, [
            'pid'          => 0,
            'page_uid'     => $pageUid,
            'mode'         => $mode,
            'status'       => 'pending',
            'reason'       => $reason,
            'snippet_json' => $json,
            'content_hash' => $contentHash,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /** @return array<int, array<string,mixed>> */
    public function list(string|array $status = 'pending'): array
    {
        $conn = $this->conn();
        $qb = $conn->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('updated_at', 'DESC');

        if ($status !== 'all') {
            if (is_array($status)) {
                $qb->where($qb->expr()->in('status', ':statuses'))
                    ->setParameter('statuses', $status, Connection::PARAM_STR_ARRAY);
            } else {
                $qb->where('status = :status')
                    ->setParameter('status', $status);
            }
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /** @return array<string,mixed>|null */
    public function get(int $uid): ?array
    {
        $row = $this->conn()->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('uid = :uid')
            ->setParameter('uid', $uid, ParameterType::INTEGER)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function setStatus(int $uid, string $status, string $error = ''): void
    {
        $this->conn()->update(self::TABLE, [
            'status'     => $status,
            'last_error' => $error,
            'updated_at' => time(),
        ], [
            'uid' => $uid,
        ]);
    }

    /** @return array<int, array<string,mixed>> */
    public function popPending(int $limit = 20): array
    {
        return $this->claimAndFetch('pending', $limit);
    }

    /** @return array<int, array<string,mixed>> */
    public function popApproved(int $limit = 20): array
    {
        return $this->claimAndFetch('approved', $limit);
    }

    /** @return array<int, array<string,mixed>> */
    private function claimAndFetch(string $status, int $limit): array
    {
        $conn  = $this->conn();
        $limit = max(1, $limit);

        // 1) Kandidaten holen
        $rows = $conn->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('status = :status')
            ->setParameter('status', $status)
            ->orderBy('updated_at', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        $uids = array_values(array_filter(array_map(
            static fn(array $r): int => (int)($r['uid'] ?? 0),
            $rows
        )));

        if ($uids === []) {
            return [];
        }

        // 2) claim -> processing
        $now = time();
        $in  = implode(',', array_fill(0, count($uids), '?'));

        // Reihenfolge muss exakt zu SQL passen:
        // SET status=?, updated_at=?  WHERE uid IN (...) AND status=?
        $params = array_merge(['processing', $now], $uids, [$status]);
        $types  = array_merge(
            [ParameterType::STRING, ParameterType::INTEGER],
            array_fill(0, count($uids), ParameterType::INTEGER),
            [ParameterType::STRING]
        );

        $conn->executeStatement(
            'UPDATE ' . self::TABLE . '
             SET status = ?, updated_at = ?
             WHERE uid IN (' . $in . ') AND status = ?',
            $params,
            $types
        );

        // 3) frisch zurückholen
        return $conn->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('uid IN (:uids)')
            ->setParameter('uids', $uids, Connection::PARAM_INT_ARRAY)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function purgeByStatus(string $status, int $olderThanTs = 0): int
    {
        $conn = $this->conn();
        $qb = $conn->createQueryBuilder();

        $qb->delete(self::TABLE)
            ->where(
                $qb->expr()->eq('status', $qb->createNamedParameter($status))
            );

        if ($olderThanTs > 0) {
            $qb->andWhere(
                $qb->expr()->lt('updated_at', $qb->createNamedParameter($olderThanTs, ParameterType::INTEGER))
            );
        }

        return (int)$qb->executeStatement();
    }

    public function pageHasEnabledItems(int $pid): bool
    {
        $pidChain = $this->buildPidChainToRoot($pid);

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_siterichsnippets_item');

        $qb->getRestrictions()->removeAll();

        $count = (int)$qb
            ->count('uid')
            ->from('tx_siterichsnippets_item')
            ->where(
                $qb->expr()->in(
                    'pid',
                    $qb->createNamedParameter($pidChain, Connection::PARAM_INT_ARRAY)
                ),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->eq('active', $qb->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }

    private function buildPidChainToRoot(int $pid): array
    {
        $chain = [];
        $current = $pid;

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();

        while ($current > 0 && !in_array($current, $chain, true)) {
            $chain[] = $current;

            $parent = $qb->select('pid')
                ->from('pages')
                ->where(
                    $qb->expr()->eq(
                        'uid',
                        $qb->createNamedParameter($current, ParameterType::INTEGER)
                    )
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            $current = (int)$parent;
        }

        return $chain;
    }
}