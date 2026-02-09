<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PageTreeScanner
{
    /**
     * Liefert alle sichtbaren Seiten (kein Sysfolder/Recycler).
     * @return array<int, array{uid:int,pid:int,title:string,slug:string,doktype:int,sys_language_uid:int}>
     */
    public function fetchAllPages(int $rootPid): array
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $root = $conn->select(
            ['uid','pid','title','slug','doktype','sys_language_uid','hidden','deleted'],
            'pages',
            ['uid' => $rootPid]
        )->fetchAssociative();

        if (!$root || (int)$root['deleted'] === 1) {
            return [];
        }

        $result = [];
        $queue  = [(int)$rootPid];

        while ($queue) {
            $pid = (int)array_shift($queue);
            $qb = $conn->createQueryBuilder();
            $qb->select('uid','pid','title','slug','doktype','sys_language_uid','hidden','deleted')
               ->from('pages')
               ->where(
                   $qb->expr()->eq('pid', $qb->createNamedParameter($pid, ParameterType::INTEGER))
               )
               ->orderBy('sorting', 'ASC');

            foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
                if ((int)$row['deleted'] === 1) {
                    continue;
                }
                $doktype = (int)$row['doktype'];
                if ($doktype >= 200) {
                    continue; // Sysfolder usw.
                }

                $result[] = [
                    'uid' => (int)$row['uid'],
                    'pid' => (int)$row['pid'],
                    'title' => (string)$row['title'],
                    'slug' => (string)$row['slug'],
                    'doktype' => $doktype,
                    'sys_language_uid' => (int)$row['sys_language_uid'],
                ];

                $queue[] = (int)$row['uid'];
            }
        }

        array_unshift($result, [
            'uid' => (int)$root['uid'],
            'pid' => (int)$root['pid'],
            'title' => (string)$root['title'],
            'slug' => (string)$root['slug'],
            'doktype' => (int)$root['doktype'],
            'sys_language_uid' => (int)$root['sys_language_uid'],
        ]);

        return $result;
    }
}
