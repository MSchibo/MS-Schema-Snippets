<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SnippetInserter
{
    private const HEADER = 'JSON-LD (Rich Snippet)';

    /**
     * Ersetzt vorhandenes JSON-LD-Snippet (CType=html, Header s.u.) oder legt ein neues am Seitenende an.
     * Optional: $afterUid für gezieltes Einfügen unter einem Element (verwenden nur, wenn wirklich nötig).
     * @return int tt_content.uid des Snippet-Elements
     */
    public function upsert(int $pid, string $json, ?int $afterUid = null): int
    {
        if ($json === '' || trim($json) === '{}') {
            return 0;
        }

        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        $existingUid = $this->findExistingSnippetUid($pid);
        $bodytext = '<script type="application/ld+json">'.$json.'</script>';

        if ($existingUid) {
            $conn->update('tt_content', [
                'bodytext' => $bodytext,
                'tstamp'   => time(),
            ], ['uid' => $existingUid]);

            return (int)$existingUid;
        }

        // neues Element
        $sorting = $afterUid ? $this->sortingAfter($afterUid) : $this->nextSortingAtEnd($pid);

        $conn->insert('tt_content', [
            'pid'              => $pid,
            'CType'            => 'html',
            'header'           => self::HEADER,
            'bodytext'         => $bodytext,
            'sorting'          => $sorting,
            'tstamp'           => time(),
            'crdate'           => time(),
            'hidden'           => 0,
            'deleted'          => 0,
            'sys_language_uid' => 0,
        ]);

        return (int)$conn->lastInsertId('tt_content');
    }

    /**
     * Bestehendes Element unterhalb $afterUid einfügen (falls du das weiter brauchst).
     * Hinweis: In v13 bitte **kein** cruser_id mehr setzen.
     */
    public function insertBelow(int $pid, int $afterUid, string $scriptTag): int
    {
        if ($scriptTag === '') {
            return 0;
        }
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');

        $sorting = $this->sortingAfter($afterUid);

        $conn->insert('tt_content', [
            'pid'              => $pid,
            'CType'            => 'html',
            'header'           => self::HEADER,
            'bodytext'         => $scriptTag,
            'sorting'          => $sorting,
            'tstamp'           => time(),
            'crdate'           => time(),
            'hidden'           => 0,
            'deleted'          => 0,
            'sys_language_uid' => 0,
        ]);

        return (int)$conn->lastInsertId('tt_content');
    }

    /** true, wenn auf der Seite bereits ein Snippet liegt */
    public function exists(int $pid): bool
    {
        return $this->findExistingSnippetUid($pid) !== null;
    }

    /** Liefert die uid eines vorhandenen Snippet-Elements */
    public function findExistingSnippetUid(int $pid): ?int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');

        $row = $qb->select('uid')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('CType', $qb->createNamedParameter('html')),
                $qb->expr()->eq('header', $qb->createNamedParameter(self::HEADER))
            )
            ->orderBy('sorting', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ? (int)$row['uid'] : null;
    }

    private function nextSortingAtEnd(int $pid): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $max = (int)$qb->selectLiteral('MAX(sorting) AS s')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchOne();

        return ($max > 0 ? $max : 0) + 256;
    }

    private function sortingAfter(int $afterUid): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $sorting = (int)$qb->select('sorting')
            ->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($afterUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();

        return ($sorting > 0 ? $sorting : 0) + 128;
    }
        /**
     * Löscht das vorhandene Snippet-Element auf der Seite (soft delete).
     * @return bool true, wenn ein Element gefunden und gelöscht wurde
     */
    // in Classes/Service/SnippetInserter.php

public function deleteByUid(int $contentUid): bool
{
    if ($contentUid <= 0) { return false; }
    $conn = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
        ->getConnectionForTable('tt_content');

    $affected = $conn->update(
        'tt_content',
        ['deleted' => 1, 'tstamp' => time()],
        ['uid' => $contentUid]
    );
    return $affected > 0;
}

/** Löscht (soft delete) das erste gefundene Snippet-Element auf der Seite */
public function delete(int $pid): bool
{
    $uid = $this->findExistingSnippetUid($pid);
    if ($uid === null) { return false; }
    return $this->deleteByUid($uid);
}

}