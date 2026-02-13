<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Scheduler;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use MyVendor\SiteRichSnippets\Service\PageTreeScanner;
use MyVendor\SiteRichSnippets\Service\ContentAnalyzer;
use MyVendor\SiteRichSnippets\Service\QueueFactory;
use MyVendor\SiteRichSnippets\Service\QueueInterface;
use MyVendor\SiteRichSnippets\Snippet\SnippetService;

final class ScanSiteToQueueTask extends AbstractTask
{
    public function execute(): bool
    {
        $this->log('start');

        try {
            $rootPid = $this->resolveRootPidViaSiteFinder();
            $this->log('rootPid_resolved', ['rootPid' => $rootPid]);

            if ($rootPid <= 0) {
                $this->log('no_rootPid');
                return true;
            }

            /** @var PageTreeScanner $scanner */
            $scanner  = GeneralUtility::makeInstance(PageTreeScanner::class);
            /** @var ContentAnalyzer $analyzer */
            $analyzer = GeneralUtility::makeInstance(ContentAnalyzer::class);
            /** @var SnippetService $snippetService */
            $snippetService = GeneralUtility::makeInstance(SnippetService::class);
            /** @var QueueInterface $queue */
            $queue    = QueueFactory::create();

            $pages = $scanner->fetchAllPages($rootPid);
            $this->log('pages', ['count' => is_array($pages) ? count($pages) : 0]);

            // Wenn SiteFinder zwar was geliefert hat, aber Scan 0 Seiten -> hart auf Fallback
            if (empty($pages)) {
                $this->log('pages_empty_retry', ['rootPid' => $rootPid]);

                $rootPid2 = $this->resolveRootPidWithoutSiteFinder();
                $this->log('rootPid_retry', ['rootPid' => $rootPid2]);

                if ($rootPid2 > 0) {
                    $rootPid = $rootPid2;
                    $pages   = $scanner->fetchAllPages($rootPid);
                    $this->log('pages_retry', ['count' => is_array($pages) ? count($pages) : 0]);
                }
            }

            if (empty($pages)) {
                $this->log('no_pages', ['rootPid' => $rootPid]);
                $this->log('done', ['added' => 0]);
                return true;
            }

            $added = 0;
            $built = 0;

            foreach ($pages as $pRow) {
                $pid = (int)($pRow['uid'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }

                $data = $analyzer->analyzePageContents($pid);
                if (method_exists($analyzer, 'enrichHints')) {
                    $data = $analyzer->enrichHints($data);
                }

                // NEU: zentraler Generator
                $jsonld = $snippetService->composeGraphForPage($pRow, $data);
                if (empty($jsonld) || !is_array($jsonld)) {
                    continue;
                }
                $built++;

                $json = json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($json) || trim($json) === '') {
                    continue;
                }

                $queue->addOrUpdate(
                    $pid,
                    'semi',
                    $json,
                    sha1($json),
                    'schedulerScan'
                );
                $added++;
            }

            $this->log('summary', ['built' => $built, 'added' => $added]);
            $this->log('done', ['added' => $added]);
            return true;

        } catch (\Throwable $e) {
            $this->log('exception', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    private function resolveRootPidViaSiteFinder(): int
    {
        try {
            /** @var SiteFinder $sf */
            $sf = GeneralUtility::makeInstance(SiteFinder::class);
            $sites = $sf->getAllSites();

            $this->log('sites', ['count' => is_array($sites) ? count($sites) : 0]);

            if (empty($sites)) {
                $this->log('no_sites');
                return $this->resolveRootPidWithoutSiteFinder();
            }

            $firstSite = reset($sites);
            $rootPid   = (int)$firstSite->getRootPageId();

            $this->log('root_from_sitefinder', ['rootPid' => $rootPid, 'sites' => count($sites)]);

            // TYPO3: rootPid=1 ist oft nur der "Page Tree Root" -> unbrauchbar
            if ($rootPid <= 1) {
                $this->log('sitefinder_root_unusable', ['rootPid' => $rootPid]);
                return $this->resolveRootPidWithoutSiteFinder();
            }

            return $rootPid;
        } catch (\Throwable $e) {
            $this->log('sitefinder_error', ['msg' => $e->getMessage()]);
            return $this->resolveRootPidWithoutSiteFinder();
        }
    }

    private function resolveRootPidWithoutSiteFinder(): int
    {
        // 1) is_siteroot=1
        try {
            $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll();

            $row = $qb->select('uid')
                ->from('pages')
                ->where(
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                    $qb->expr()->eq('hidden', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                    $qb->expr()->eq('is_siteroot', $qb->createNamedParameter(1, ParameterType::INTEGER))
                )
                ->orderBy('uid', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            $pid = (int)($row['uid'] ?? 0);
            if ($pid > 0) {
                $this->log('root_from_is_siteroot', ['rootPid' => $pid]);
                return $pid;
            }
        } catch (\Throwable $e) {
            $this->log('root_from_is_siteroot_error', ['msg' => $e->getMessage()]);
        }

        // 2) erster Child von PID 1 (oft = 2)
        try {
            $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll();

            $row = $qb->select('uid')
                ->from('pages')
                ->where(
                    $qb->expr()->eq('pid', $qb->createNamedParameter(1, ParameterType::INTEGER)),
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                    $qb->expr()->eq('hidden', $qb->createNamedParameter(0, ParameterType::INTEGER))
                )
                ->orderBy('sorting', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            $pid = (int)($row['uid'] ?? 0);
            if ($pid > 0) {
                $this->log('root_from_first_child_of_1', ['rootPid' => $pid]);
                return $pid;
            }
        } catch (\Throwable $e) {
            $this->log('root_from_first_child_error', ['msg' => $e->getMessage()]);
        }

        // 3) absoluter Fallback: erste existierende Page
        try {
            $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll();

            $row = $qb->select('uid')
                ->from('pages')
                ->where(
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                    $qb->expr()->eq('hidden', $qb->createNamedParameter(0, ParameterType::INTEGER))
                )
                ->orderBy('uid', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            $pid = (int)($row['uid'] ?? 0);
            if ($pid > 0) {
                $this->log('root_from_first_page', ['rootPid' => $pid]);
                return $pid;
            }
        } catch (\Throwable $e) {
            $this->log('root_from_first_page_error', ['msg' => $e->getMessage()]);
        }

        return 0;
    }

    private function log(string $tag, array $data = []): void
    {
        try {
            $dir = '/tmp/site_richsnippets';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            @file_put_contents(
                $dir . '/SCHEDULER.log',
                '[' . date('Y-m-d H:i:s') . '] SCAN ' . $tag . ' ' . json_encode($data) . PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            // nie crashen
        }
    }
}