<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Scheduler;

use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use MyVendor\SiteRichSnippets\Service\QueueFactory;
use MyVendor\SiteRichSnippets\Service\QueueInterface;
use MyVendor\SiteRichSnippets\Service\SnippetInserter;

final class ProcessApprovedQueueTask extends AbstractTask
{
    public function execute(): bool
    {
        $this->log('start');

        try {
            $conf       = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['site_richsnippets'] ?? [];
            $batchLimit = max(1, (int)($conf['batchLimit'] ?? 50));

            /** @var QueueInterface $queue */
            $queue = QueueFactory::create();

            $this->log('queue_class', ['class' => get_class($queue)]);


            /** @var SnippetInserter $inserter */
            $inserter = GeneralUtility::makeInstance(SnippetInserter::class);

            // pending zählen (Task 1 schreibt pending)
            $pendingCount = 0;
            try {
                $pendingCount = count($queue->list('pending'));
            } catch (\Throwable $e) {
                $this->log('pending_count_failed', ['msg' => $e->getMessage()]);
            }
            $this->log('pending_count', ['count' => $pendingCount, 'batchLimit' => $batchLimit]);

            // pending claimen -> processing
            $items = $queue->popPending($batchLimit);
            $this->log('pending_claimed', ['count' => count($items)]);

            $ok = 0;
            $errors = [];

            foreach ($items as $item) {
                $uid  = (int)($item['uid'] ?? 0);
                $pid  = (int)($item['page_uid'] ?? 0);
                $json = (string)($item['snippet_json'] ?? '');

                if ($uid <= 0 || $pid <= 0 || trim($json) === '') {
                    if ($uid > 0) {
                        $queue->setStatus($uid, 'error', 'Invalid queue item (uid/pid/json missing)');
                    }
                    $errors[] = 'Invalid item: uid=' . $uid . ' pid=' . $pid;
                    continue;
                }

                try {
                    // auto-approve (für Nachvollziehbarkeit)
                    $queue->setStatus($uid, 'approved', 'autoApproved');

                    // einfügen
                    $inserter->upsert($pid, $json, null);

                    // done
                    $queue->setStatus($uid, 'done');
                    $ok++;
                } catch (\Throwable $e) {
                    $queue->setStatus($uid, 'error', $e->getMessage());
                    $errors[] = 'PID ' . $pid . ': ' . $e->getMessage();
                }
            }

            $this->log('done', ['ok' => $ok, 'errors' => $errors]);
            return true;
        } catch (\Throwable $e) {
            $this->log('exception', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    private function log(string $tag, array $data = []): void
    {
        try {
            $dir = '/tmp/site_richsnippets';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $line = '[' . date('Y-m-d H:i:s') . '] PROCESS ' . $tag . ' ' . json_encode($data) . PHP_EOL;
            @file_put_contents($dir . '/SCHEDULER.log', $line, FILE_APPEND);
        } catch (\Throwable $e) {
            // nie crashen
        }
    }
}
