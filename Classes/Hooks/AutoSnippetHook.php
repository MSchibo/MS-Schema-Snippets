<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Hooks;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use MyVendor\SiteRichSnippets\Service\ContentAnalyzer;
use MyVendor\SiteRichSnippets\Service\QueueService;
use MyVendor\SiteRichSnippets\Snippet\SnippetService;

final class AutoSnippetHook
{    
    /**
     * Wird nach allen Datamap-Operationen aufgerufen.
     */
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $affectedPids = [];

        // datamap: [tableName => [uid => fieldArray]]
        foreach (($dataHandler->datamap ?? []) as $table => $records) {
            if ($table !== 'pages' && $table !== 'tt_content') {
                continue;
            }

            foreach ($records as $uid => $_) {
                $pid = 0;

                // Neue Datensätze (NEW...)
                if (is_string($uid) && str_starts_with($uid, 'NEW')) {
                    $finalUid = (int)($dataHandler->substNEWwithIDs[$uid] ?? 0);
                    if ($finalUid > 0) {
                        if ($table === 'pages') {
                            $pid = $finalUid;
                        } else {
                            $info = $dataHandler->recordInfo('tt_content', $finalUid, 'pid');
                            $pid  = (int)($info['pid'] ?? 0);
                        }
                    }
                } else {
                    $intUid = (int)$uid;
                    if ($table === 'pages') {
                        $pid = $intUid;
                    } else {
                        $info = $dataHandler->recordInfo('tt_content', $intUid, 'pid');
                        $pid  = (int)($info['pid'] ?? 0);
                    }
                }

                if ($pid > 0) {
                    $affectedPids[$pid] = true;
                }
            }
        }

        if ($affectedPids === []) {
            return;
        }

        /** @var QueueService $queueDb */
        $queueDb = GeneralUtility::makeInstance(QueueService::class);

        /** @var ContentAnalyzer $an */
        $an = GeneralUtility::makeInstance(ContentAnalyzer::class);

        /** @var SnippetService $snippetService */
        $snippetService = GeneralUtility::makeInstance(SnippetService::class);

        foreach (array_keys($affectedPids) as $pid) {

                if (!$this->queueService->pageHasEnabledItems($pageId)) {
                    continue;
                }
            try {
                $pageRow = $dataHandler->recordInfo('pages', (int)$pid, '*');
                if (!$pageRow) {
                    continue;
                }

                $data = $an->analyzePageContents((int)$pid);
                if (method_exists($an, 'enrichHints')) {
                    $data = $an->enrichHints($data);
                }

                // NEU: zentraler Generator
                $jsonld = $snippetService->composeGraphForPage($pageRow, $data);
                if (empty($jsonld) || !is_array($jsonld)) {
                    continue;
                }

                $json = json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($json) || $json === '') {
                    continue;
                }

                $hash = sha1($json);

                // DB-Queue (einheitlich für TYPO3 11–13)
                $queueDb->addOrUpdate((int)$pid, 'auto', $json, $hash, 'autoHook');
            } catch (\Throwable $e) {
                // Backend darf nie crashen
            }
        }
    }
}