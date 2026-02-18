<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Backend;

use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// CSRF v13+
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use MyVendor\SiteRichSnippets\Service\PageTreeScanner;
use MyVendor\SiteRichSnippets\Service\ContentAnalyzer;
use MyVendor\SiteRichSnippets\Service\SnippetService;
use MyVendor\SiteRichSnippets\Service\SnippetInserter;
use MyVendor\SiteRichSnippets\Service\QueueService;
use MyVendor\SiteRichSnippets\Service\SettingsService;
use MyVendor\SiteRichSnippets\Service\ActionLog;

final class ModuleBootstrap
{
    private array $stats = ['scanned' => 0, 'suggestions' => 0];

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $get  = (array)$request->getQueryParams();
        $post = (array)$request->getParsedBody();

        // GET + POST zusammenführen, POST überschreibt GET
        $q = array_merge($get, $post);

        $id     = (int)($q['id'] ?? 0);
        $scan   = $q['scan'] ?? null;
        $action = $q['action'] ?? null;

        $this->dbg('entry', [
            'method' => $request->getMethod(),
            'get'    => $get,
            'post'   => $post,
            'id'     => $id,
            'scan'   => $scan,
            'action' => $action,
            'q'      => $q,
        ]);

        // ===== QUEUE: Undo eingepflegter Eintrag (Status done -> pending + Snippet löschen) =====
        if ($action === 'queueUndo' && $request->getMethod() === 'POST' && isset($q['qid'])) {
            if ($resp = $this->assertValidTokenOrRedirect($request, $q)) { return $resp; }

            $qid = (int)$q['qid'];

            /** @var QueueService $qs */
            $qs = GeneralUtility::makeInstance(QueueService::class);
            $allRows = $qs->list('all');
            $row = null;

            foreach ($allRows as $r) {
                if ((int)($r['uid'] ?? 0) === $qid) {
                    $row = $r;
                    break;
                }
            }

            if (!$row) {
                return new RedirectResponse(
                    $this->moduleUrl([
                        'id'     => (int)($q['id'] ?? 0),
                        'action' => 'queue',
                        'msg'    => 'Undo fehlgeschlagen: Eintrag nicht gefunden.',
                    ]),
                    303
                );
            }

            $pid = (int)($row['page_uid'] ?? 0);

            /** @var SnippetInserter $insert */
            $insert = GeneralUtility::makeInstance(SnippetInserter::class);

            try {
                // Snippet auf der Seite entfernen
                if (method_exists($insert, 'delete')) {
                    $insert->delete($pid);
                } else {
                    // Fallback: leeren Inhalt schreiben
                    $insert->upsert($pid, '', null);
                }

                // Queue-Eintrag wieder auf "pending" setzen
                $qs->setStatus($qid, 'pending', 'undo');

                $msg = 'Snippet auf PID '.$pid.' entfernt, Eintrag wieder bei "Ausstehende Freigaben".';
            } catch (\Throwable $e) {
                $msg = 'Undo-Fehler: ' . $e->getMessage();
            }

            return new RedirectResponse(
                $this->moduleUrl([
                    'id'     => (int)($q['id'] ?? 0),
                    'action' => 'queue',
                    'msg'    => $msg,
                ]),
                303
            );
        }

        // ===== QUEUE: Rejected löschen (purge) =====
        if ($action === 'queuePurgeRejected' && $request->getMethod() === 'POST') {
            if ($resp = $this->assertValidTokenOrRedirect($request, $q)) { return $resp; }

            /** @var QueueService $qs */
            $qs = GeneralUtility::makeInstance(QueueService::class);

            // optional: nur ältere löschen, z.B. 30 Tage
            $days = (int)($q['days'] ?? 0);
            $olderThan = 0;
            if ($days > 0) {
                $olderThan = time() - ($days * 86400);
            }

            try {
                $cnt = $qs->purgeByStatus('rejected', $olderThan);
                $msg = $cnt . ' abgelehnte Einträge gelöscht.';
            } catch (\Throwable $e) {
                $msg = 'Löschen fehlgeschlagen: ' . $e->getMessage();
            }

            return new RedirectResponse(
                $this->moduleUrl(['id' => (int)($q['id'] ?? 0), 'action' => 'queue', 'msg' => $msg]),
                303
            );
        }

        // ===== Einzel-JSON: Download =====
        if ($action === 'download' && isset($q['pid'])) {
            $pid      = (int)$q['pid'];
            $json     = $this->buildSuggestionJsonForPid($pid);
            $title    = (string)($this->getPageRow($pid)['title'] ?? 'snippet');
            $filename = $this->slugify($title) . '.json';

            $response = (new Response())
                ->withHeader('Content-Type', 'application/ld+json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="'.$filename.'"');
            $response->getBody()->write($json);
            return $response;
        }

        // ===== Einzel-JSON: View =====
        if ($action === 'view' && isset($q['pid'])) {
            $pid  = (int)$q['pid'];
            $json = $this->buildSuggestionJsonForPid($pid);
            $back = $this->moduleUrl([
                'id'   => (int)($q['id'] ?? $pid),
                'scan' => (string)($q['scan'] ?? 'site'),
            ]);

            $html = '<div style="padding:14px">'
                  . '<p><a class="btn btn-default" href="'.$this->h($back).'">← Zurück</a></p>'
                  . '<pre style="padding:20px;font:12px/1.5 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-word;">'
                  . $this->h($json)
                  . '</pre></div>';

            return $this->html($html);
        }

        // ===== Insert =====
        if ($action === 'insert' && isset($q['pid'])) {
            // CSRF nur bei POST hart prüfen, GET lassen wir (zur Not) durch,
            // damit der Button im Backend sicher funktioniert.
            if ($request->getMethod() === 'POST') {
                if ($resp = $this->assertValidTokenOrRedirect($request, $q)) {
                    return $resp;
                }
            }

            $pid  = (int)$q['pid'];
            $json = $this->buildSuggestionJsonForPid($pid);

            $this->dbg('insert_called', [
                'method'     => $request->getMethod(),
                'pid'        => $pid,
                'json_empty' => ($json === '' || trim($json) === '{}'),
            ]);

            if ($json === '' || trim($json) === '{}') {
                return new RedirectResponse(
                    $this->moduleUrl([
                        'id'   => $pid,
                        'scan' => (string)($q['scan'] ?? 'site'),
                        'msg'  => 'Kein valides Snippet erzeugt.',
                    ]),
                    303
                );
            }

            /** @var SnippetInserter $insert */
            $insert = GeneralUtility::makeInstance(SnippetInserter::class);

            try {
                $insert->upsert($pid, $json, null);
                $msg = 'Snippet eingefügt.';
            } catch (\Throwable $e) {
                $msg = 'Fehler beim Einfügen: ' . $e->getMessage();
                $this->dbg('insert_error', [
                    'pid' => $pid,
                    'msg' => $e->getMessage(),
                ]);
            }

            return new RedirectResponse(
                $this->moduleUrl([
                    'id'   => $pid,
                    'scan' => (string)($q['scan'] ?? 'site'),
                    'msg'  => $msg,
                ]),
                303
            );
        }

        if ($action === 'queueView' && isset($q['qid'])) {
            $qid = (int)$q['qid'];
            /** @var QueueService $qs */
            $qs = GeneralUtility::makeInstance(QueueService::class);
            $allRows = $qs->list('all');
            $row = null;
            foreach ($allRows as $r) {
                if ((int)($r['uid'] ?? 0) === $qid) {
                    $row = $r;
                    break;
                }
            }

            if (!$row) {
                return new RedirectResponse(
                    $this->moduleUrl(['id'=>(int)($q['id'] ?? 0),'action'=>'queue','msg'=>'Eintrag nicht gefunden.']),
                    303
                );
            }

            $json = (string)($row['snippet_json'] ?? '{}');
            $back = $this->moduleUrl(['id'=>(int)($q['id'] ?? 0),'action'=>'queue']);

            $html = '<div style="padding:14px">'
                  . '<p><a class="btn btn-default" href="'.$this->h($back).'">← Zurück</a></p>'
                  . '<pre style="padding:20px;font:12px/1.5 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-word;">'
                  . $this->h($json)
                  . '</pre></div>';

            return $this->html($html);
        }

        if ($action === 'queueDownload' && isset($q['qid'])) {
            $qid = (int)$q['qid'];
            /** @var QueueService $qs */
            $qs = GeneralUtility::makeInstance(QueueService::class);
            $allRows = $qs->list('all');
            $row = null;
            foreach ($allRows as $r) {
                if ((int)($r['uid'] ?? 0) === $qid) {
                    $row = $r;
                    break;
                }
            }

            if (!$row) {
                return new RedirectResponse(
                    $this->moduleUrl(['id'=>(int)($q['id'] ?? 0),'action'=>'queue','msg'=>'Eintrag nicht gefunden.']),
                    303
                );
            }

            $json = (string)($row['snippet_json'] ?? '{}');
            $pid = (int)($row['page_uid'] ?? 0);
            $title = (string)($this->getPageRow($pid)['title'] ?? 'queue_snippet');
            $filename = $this->slugify($title) . '_queue.json';

            $response = (new Response())
                ->withHeader('Content-Type', 'application/ld+json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="'.$filename.'"');
            $response->getBody()->write($json);
            return $response;
        }

        if ($action === 'queue') {
            if ($request->getMethod() === 'POST') {
                if ($resp = $this->assertValidTokenOrRedirect($request, $q)) { return $resp; }
            }

            $body    = $request->getParsedBody() ?? [];
            $infoMsg = (string)($q['msg'] ?? '');

            // ============================================
            // POST: komplette Site scannen & in Queue legen
            // ============================================
            if (!empty($body['scan_whole_site_to_queue'])) {
                /** @var SiteFinder $sf */
                $sf = GeneralUtility::makeInstance(SiteFinder::class);
                $sites = $sf->getAllSites();

                if (!empty($sites)) {
                    /** @var \TYPO3\CMS\Core\Site\Entity\Site $firstSite */
                    $firstSite = reset($sites);
                    $rootPid   = (int)$firstSite->getRootPageId();

                    /** @var PageTreeScanner $scanner */
                    $scanner      = GeneralUtility::makeInstance(PageTreeScanner::class);
                    /** @var ContentAnalyzer $analyzer */
                    $analyzer     = GeneralUtility::makeInstance(ContentAnalyzer::class);
                    /** @var \MyVendor\SiteRichSnippets\Snippet\SnippetService $snippetService */
                    $snippetService = GeneralUtility::makeInstance(\MyVendor\SiteRichSnippets\Snippet\SnippetService::class);
                    /** @var QueueService $queueService */
                    $queueService = GeneralUtility::makeInstance(QueueService::class);

                    $pages = $scanner->fetchAllPages($rootPid);
                    $added = 0;

                    foreach ($pages as $pRow) {
                        $pid = (int)$pRow['uid'];

                         if (!$queueService->pageHasEnabledItems($pid)) {
                        continue; // keine aktiven Items -> diese Seite nicht scannen
                        }

                        $data = $analyzer->analyzePageContents($pid);
                        if (method_exists($analyzer, 'enrichHints')) {
                            $data = $analyzer->enrichHints($data);
                        }

                        $jsonld = $snippetService->composeGraphForPage($pRow, $data);
                        if (empty($jsonld)) {
                            error_log("PID $pid: No JSON-LD generated - data: " . json_encode($data));
                            continue;
                        }

                        $json = json_encode(
                            $jsonld,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );
                        if (!is_string($json) || $json === '') {
                            error_log("PID $pid: Invalid JSON encoding");
                            continue;
                        }

                        // immer halbautomatisch in die Queue legen
                        try {
                            $queueService->pushPending($pid, $json, 'queueAll');
                            $added++;
                        } catch (\Throwable $e) {
                            $infoMsg .= ' Fehler bei PID ' . $pid . ': ' . $e->getMessage();
                        }
                    }

                    $infoMsg = $added > 0
                        ? $added . ' Einträge in die Queue gestellt.' . $infoMsg
                        : 'Keine Seiten mit neuen Snippets gefunden.' . $infoMsg;
                }
            }

            // ============================================
            // Queue-Einträge laden
            // ============================================
            /** @var QueueService $queue */
            $queue = GeneralUtility::makeInstance(QueueService::class);

            $allRows = $queue->list('all');

$pending  = [];
$approved = [];
$rejected = [];
$done     = []; // neu

foreach ($allRows as $row) {
    $st = (string)($row['status'] ?? 'pending');

    if ($st === 'approved') {
        $approved[] = $row;
    } elseif ($st === 'rejected') {
        $rejected[] = $row;
    } elseif ($st === 'done') {
        $done[] = $row; // Eingepflegt
    } else {
        // alles andere (inkl. 'pending' oder leer) -> Ausstehende Freigaben
        $pending[] = $row;
    }
}


            $backUrl    = $this->moduleUrl(['id' => (int)($q['id'] ?? 0)]);
            $processUrl = $this->moduleUrl(['id' => (int)($q['id'] ?? 0), 'action' => 'queueProcess']);

            // Button: komplette Site scannen (POST)
            $formScanAll  = '<form method="post" action="' . $this->h($this->moduleUrl(['id' => (int)($q['id'] ?? 0), 'action' => 'queue'])) . '" style="display:inline-block;margin-left:8px">';
            $formScanAll .= '<input type="hidden" name="scan_whole_site_to_queue" value="1">';
            $formScanAll .= '<input type="hidden" name="moduleToken" value="' . $this->h($this->moduleToken($request)) . '">';
            $formScanAll .= '<button type="submit">Jetzt komplette Site scannen &amp; in Queue legen</button>';
            $formScanAll .= '</form>';

            $html  = '<div style="padding:16px;font:14px/1.5 system-ui,Segoe UI,Roboto,Arial">';
            $html .= '<h1>Queue (Freigaben)</h1>';

            if ($infoMsg !== '') {
                $html .= '<div style="margin:8px 0;padding:8px 10px;border:1px solid #cfeccf;background:#f4fff4;color:#1c7c1c;border-radius:4px">'
                       . $this->h($infoMsg)
                       . '</div>';
            }

            // Kopfzeile / Buttons
            $html .= '<div style="margin:8px 0 16px;">';

            // Approved verarbeiten (POST)
            $html .= '<form method="post" action="' . $this->h($processUrl) . '" style="display:inline-block;margin-right:8px">'
                   . '<input type="hidden" name="moduleToken" value="' . $this->h($this->moduleToken($request)) . '">'
                   . '<button type="submit">Approved verarbeiten</button>'
                   . '</form>';

            // Zurück zum Scanner
            $html .= '<a href="' . $this->h($backUrl) . '"style="margin-right:8px">← Zurück zum Scanner</a>';

            // Jetzt komplette Site scannen & in Queue legen
            $html .= $formScanAll;

            $purgeUrl = $this->moduleUrl(['id' => (int)($q['id'] ?? 0), 'action' => 'queuePurgeRejected']);

            $html .= '<form method="post" action="' . $this->h($purgeUrl) . '" style="display:inline-block;margin-left:8px">'
                . '<input type="hidden" name="moduleToken" value="' . $this->h($this->moduleToken($request)) . '">'
                . '<input type="hidden" name="days" value="0">'
                . '<button type="submit" onclick="return confirm(\'Wirklich alle abgelehnten Einträge löschen?\')">'
                . 'Abgelehnte löschen'
                . '</button>'
                . '</form>';


            $html .= '</div>';

            // === Ausstehende Freigaben (pending) ===
            $html .= '<h2>Ausstehende Freigaben</h2>';
            if (empty($pending)) {
                $html .= '<p>Keine ausstehenden Einträge.</p>';
            } else {
                $html .= '<table class="typo3-dblist" style="border-collapse:collapse;width:100%;max-width:900px">';
                $html .= '<tr><th style="text-align:left;padding:4px 6px;">UID</th><th style="text-align:left;padding:4px 6px;">PID</th><th style="text-align:left;padding:4px 6px;">Reason</th><th style="text-align:left;padding:4px 6px;">Updated</th><th style="text-align:left;padding:4px 6px;">Aktion</th></tr>';

                foreach ($pending as $row) {
                    $uid     = (int)($row['uid'] ?? 0);
                    $pid     = (int)($row['page_uid'] ?? 0);
                    $reason  = (string)($row['reason'] ?? '');
                    $updated = $this->fmtTs((int)($row['updated_at'] ?? 0));

                    $approveUrl = $this->moduleUrl(['id' => (int)($q['id'] ?? 0), 'action' => 'queueApprove']);
                    $rejectUrl  = $this->moduleUrl(['id' => (int)($q['id'] ?? 0), 'action' => 'queueReject']);
                    $viewUrl = $this->moduleUrl(['id' => (int)($q['id'] ?? 0), 'action' => 'queueView', 'qid' => $uid]);
                    $downloadUrl = $this->moduleUrl(['id' => (int)($q['id'] ?? 0), 'action' => 'queueDownload', 'qid' => $uid]);

                    $html .= '<tr>';
                    $html .= '<td style="padding:4px 6px;">' . $uid . '</td>';
                    $html .= '<td style="padding:4px 6px;">' . $pid . '</td>';
                    $html .= '<td style="padding:4px 6px;">' . $this->h($reason) . '</td>';
                    $html .= '<td style="padding:4px 6px;">' . $this->h($updated) . '</td>';
                    $html .= '<td style="padding:4px 6px;">'
                           . '<a href="' . $this->h($viewUrl) . '">View</a> | '
                           . '<a href="' . $this->h($downloadUrl) . '">Download</a> | '
                           . '<form method="post" action="' . $this->h($approveUrl) . '" style="display:inline;">'
                           . '<input type="hidden" name="qid" value="' . $uid . '">'
                           . '<input type="hidden" name="moduleToken" value="' . $this->h($this->moduleToken($request)) . '">'
                           . '<button type="submit">Approve</button>'
                           . '</form> | '
                           . '<form method="post" action="' . $this->h($rejectUrl) . '" style="display:inline;">'
                           . '<input type="hidden" name="qid" value="' . $uid . '">'
                           . '<input type="hidden" name="moduleToken" value="' . $this->h($this->moduleToken($request)) . '">'
                           . '<button type="submit">Reject</button>'
                           . '</form>'
                           . '</td>';
                    $html .= '</tr>';
                }

                $html .= '</table>';
            }

            // === Freigegeben (approved) ===
            $html .= '<h2>Freigegeben (bereit zum Einfügen)</h2>';
            if (empty($approved)) {
                $html .= '<p>Keine freigegebenen Einträge.</p>';
            } else {
                $html .= '<ul>';
                foreach ($approved as $row) {
                    $uid     = (int)($row['uid'] ?? 0);
                    $pid     = (int)($row['page_uid'] ?? 0);
                    $reason  = (string)($row['reason'] ?? '');
                    $updated = $this->fmtTs((int)($row['updated_at'] ?? 0));
                    $html .= '<li>UID ' . $uid . ' (PID ' . $pid . ') – ' . $this->h($reason) . ' – ' . $this->h($updated) . '</li>';
                }
                $html .= '</ul>';
            }

            // === Abgelehnt (rejected) ===
            $html .= '<h2>Abgelehnt</h2>';
            if (empty($rejected)) {
                $html .= '<p>Keine abgelehnten Einträge.</p>';
            } else {
                $html .= '<ul>';
                foreach ($rejected as $row) {
                    $uid     = (int)($row['uid'] ?? 0);
                    $pid     = (int)($row['page_uid'] ?? 0);
                    $reason  = (string)($row['reason'] ?? '');
                    $updated = $this->fmtTs((int)($row['updated_at'] ?? 0));
                    $html .= '<li>UID ' . $uid . ' (PID ' . $pid . ') – ' . $this->h($reason) . ' – ' . $this->h($updated) . '</li>';
                }
                $html .= '</ul>';
            }

            // === Eingepflegt (done) ===
$html .= '<h2>Eingepflegt</h2>';
if (empty($done)) {
    $html .= '<p>Keine eingepflegten Einträge.</p>';
} else {
    $html .= '<table class="typo3-dblist" style="border-collapse:collapse;width:100%;max-width:900px">';
    $html .= '<tr><th style="text-align:left;padding:4px 6px;">UID</th>'
           . '<th style="text-align:left;padding:4px 6px;">PID</th>'
           . '<th style="text-align:left;padding:4px 6px;">Reason</th>'
           . '<th style="text-align:left;padding:4px 6px;">Updated</th>'
           . '<th style="text-align:left;padding:4px 6px;">Aktion</th></tr>';

    $undoUrl = $this->moduleUrl([
        'id'     => (int)($q['id'] ?? 0),
        'action' => 'queueUndo',
    ]);

    foreach ($done as $row) {
        $uid     = (int)($row['uid'] ?? 0);
        $pid     = (int)($row['page_uid'] ?? 0);
        $reason  = (string)($row['reason'] ?? '');
        $updated = $this->fmtTs((int)($row['updated_at'] ?? 0));

        $viewUrl     = $this->moduleUrl(['id' => (int)($q['id'] ?? 0), 'action' => 'queueView',     'qid' => $uid]);
        $downloadUrl = $this->moduleUrl(['id' => (int)($q['id'] ?? 0), 'action' => 'queueDownload', 'qid' => $uid]);

        $html .= '<tr>';
        $html .= '<td style="padding:4px 6px;">' . $uid . '</td>';
        $html .= '<td style="padding:4px 6px;">' . $pid . '</td>';
        $html .= '<td style="padding:4px 6px;">' . $this->h($reason) . '</td>';
        $html .= '<td style="padding:4px 6px;">' . $this->h($updated) . '</td>';
        $html .= '<td style="padding:4px 6px;">'
               . '<a href="' . $this->h($viewUrl) . '">View</a> | '
               . '<a href="' . $this->h($downloadUrl) . '">Download</a> | '
               . '<form method="post" action="' . $this->h($undoUrl) . '" style="display:inline;">'
               . '<input type="hidden" name="qid" value="' . $uid . '">'
               . '<input type="hidden" name="moduleToken" value="' . $this->h($this->moduleToken($request)) . '">'
               . '<button type="submit">Undo</button>'
               . '</form>'
               . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table>';
}


            $html .= '</div>';

            return $this->html($html);
    }
       

        // ===== QUEUE: approve / reject =====
        if (($action === 'queueApprove' || $action === 'queueReject') && $request->getMethod() === 'POST' && isset($q['qid'])) {
            if ($resp = $this->assertValidTokenOrRedirect($request, $q)) { return $resp; }

            $qid = (int)$q['qid'];
            /** @var QueueService $qs */
            $qs  = GeneralUtility::makeInstance(QueueService::class);
            $status = $action === 'queueApprove' ? 'approved' : 'rejected';
            try {
                $qs->setStatus($qid, $status);
                $msg = 'Status geändert zu ' . $status . '.';
            } catch (\Throwable $e) {
                $msg = 'Fehler: ' . $e->getMessage();
            }

            return new RedirectResponse(
                $this->moduleUrl(['id'=>(int)($q['id'] ?? 0),'action'=>'queue','msg'=>$msg]),
                303
            );
        }

        // ===== QUEUE: Approved verarbeiten =====
        if ($action === 'queueProcess' && $request->getMethod() === 'POST') {
            if ($resp = $this->assertValidTokenOrRedirect($request, $q)) { return $resp; }

            $conf       = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['site_richsnippets'] ?? [];
            $batchLimit = (int)($conf['batchLimit'] ?? 50);

            /** @var QueueService $qs */
            $qs       = GeneralUtility::makeInstance(QueueService::class);
            /** @var SnippetInserter $inserter */
            $inserter = GeneralUtility::makeInstance(SnippetInserter::class);

            $ok = 0; $errors = [];
            foreach ($qs->popApproved(max(1, $batchLimit)) as $item) {
                try {
                    $inserter->upsert((int)$item['page_uid'], (string)$item['snippet_json'], null);
                    $qs->setStatus((int)$item['uid'], 'done');
                    $ok++;
                } catch (\Throwable $e) {
                    $qs->setStatus((int)$item['uid'], 'error', $e->getMessage());
                    $errors[] = 'PID ' . (int)$item['page_uid'] . ': ' . $e->getMessage();
                }
            }

            $msg = $ok . ' Einträge verarbeitet.';
            if (!empty($errors)) {
                $msg .= ' Fehler: ' . implode(', ', $errors);
            }

            return new RedirectResponse(
                $this->moduleUrl(['id'=>(int)($q['id'] ?? 0),'action'=>'queue','msg'=>$msg]),
                303
            );
        }

        // ===== Apply All (Direkt schreiben) =====
        if ($action === 'apply_all') {
            if ($request->getMethod() === 'POST') {
                if ($resp = $this->assertValidTokenOrRedirect($request, $q)) { return $resp; }
            }

            $rootId  = (int)($q['id'] ?? 0);
            $confirm = (string)($q['confirm'] ?? '');

            $pages = $this->scanWholeSite($rootId);
            $pids  = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['uid'], $pages))));

            if ($confirm !== 'yes') {
                $backUrl = $this->moduleUrl(['id' => $rootId, 'scan' => 'site']);
                $runUrl  = $this->moduleUrl(['id' => $rootId, 'scan' => 'site', 'action' => 'apply_all']);

                $html = '<div style="padding:16px">'
                      . '<h2>Alle anwenden</h2>'
                      . '<p>Es wurden <strong>' . count($pids) . '</strong> Seiten gefunden, für die ein Snippet vorgeschlagen wurde.</p>'
                      . '<p>Möchtest du jetzt auf allen Seiten das Snippet <em>einfügen/aktualisieren</em>?</p>'
                      . '<form method="post" action="' . $this->h($runUrl) . '">'
                      . '<input type="hidden" name="confirm" value="yes">'
                      . '<input type="hidden" name="moduleToken" value="' . $this->h($this->moduleToken($request)) . '">'
                      . '<button class="btn btn-primary" type="submit">Ja, jetzt ausführen</button>'
                      . '</form> '
                      . '<a class="btn btn-default" href="'.$backUrl.'">Abbrechen</a>'
                      . '</div>';
                return $this->html($html);
            }

            $ok = 0; $fail = 0;
            /** @var SnippetInserter $inserter */
            $inserter = GeneralUtility::makeInstance(SnippetInserter::class);

            foreach ($pids as $pid) {
                try {
                    $json = $this->buildSuggestionJsonForPid($pid);
                    if ($json === '' || trim($json) === '{}') { throw new \RuntimeException('Empty JSON'); }
                    $inserter->upsert($pid, $json, null);
                    $ok++;
                } catch (\Throwable $e) {
                    $fail++;
                }
            }

            $msg = $ok . ' Snippets angewendet, ' . $fail . ' fehlgeschlagen.';
            return new RedirectResponse($this->moduleUrl(['id'=>$rootId,'scan'=>'site', 'msg' => $msg]), 303);
        }

        // ===== Queue Dummy-Test =====
        if ($action === 'queueTest') {
            if ($resp = $this->assertValidTokenOrRedirect($request, $q)) { return $resp; }

            /** @var QueueService $qf */
            $qf = GeneralUtility::makeInstance(QueueService::class);
            $newId = $qf->addOrUpdate(123, 'semi', '{"@context":"https://schema.org","@type":"WebPage"}', 'manual test');
            return new RedirectResponse(
                $this->moduleUrl(['id'=>(int)($q['id'] ?? 0),'action'=>'queue','msg'=>'Dummy #'.$newId.' geschrieben']),
                303
            );
        }

                // ===== Review (mehrere PIDs vergleichen) =====
        if ($action === 'review' && !empty($q['pids'])) {
            $pidList = array_filter(array_map('intval', (array)$q['pids']));
            $items   = [];

            /** @var ContentAnalyzer $an */
            $an = GeneralUtility::makeInstance(ContentAnalyzer::class);
            /** @var \MyVendor\SiteRichSnippets\Snippet\SnippetService $snippetService */
            $snippetService = GeneralUtility::makeInstance(\MyVendor\SiteRichSnippets\Snippet\SnippetService::class);

            foreach ($pidList as $pid) {
                $row = $this->getPageRow($pid);
                if (!$row) { continue; }

                $data = $an->analyzePageContents($pid);
                if (method_exists($an, 'enrichHints')) {
                    $data = $an->enrichHints($data);
                }
                $jsonld = $snippetService->composeGraphForPage($row, $data);

                $items[] = [
                    'uid'   => $pid,
                    'title' => (string)($row['title'] ?? ''),
                    'path'  => $this->buildPath($pid),
                    'old'   => $this->fetchExistingSnippetJson($pid),
                    'new'   => json_encode($jsonld, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),
                ];
            }

            $backUrl = $this->moduleUrl([
                'id'   => (int)($q['id'] ?? ($pidList[0] ?? 0)),
                'scan' => (string)($q['scan'] ?? 'site'),
            ]);

            $reviewCss = <<<CSS
<style>
.rsR{font:14px/1.5 system-ui,Segoe UI,Roboto,Arial}
.rsR .wrap{max-width:1200px;margin:0 auto;padding:16px}
.rsR .top{display:flex;gap:12px;align-items:center;margin-bottom:14px}
.rsR .btn{display:inline-block;padding:6px 10px;border:1px solid #ccc;border-radius:6px;background:#fff;text-decoration:none;cursor:pointer}
.rsR .grid{display:grid;gap:12px}
.rsR .card{border:1px solid #e3e3e3;border-radius:8px;background:#fff}
.rsR .card h3{margin:0;padding:10px 12px;border-bottom:1px solid #eee;background:#fafafa;font-size:15px}
.rsR .card .body{padding:12px}
.rsR .meta{color:#666;font-size:13px;margin-bottom:8px}
.rsR pre{white-space:pre-wrap;word-break:break-word;background:#f7f7f7;border:1px solid #eee;border-radius:6px;padding:8px;max-height:260px;overflow:auto;margin:0}
.rsR .diff{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}
</style>
CSS;

            $html  = $reviewCss.'<div class="rsR"><div class="wrap">';
            $html .= '<div class="top">'
                   . '<a class="btn" href="'.$this->h($backUrl).'">← Zurück</a>'
                   . '<strong>Snippets prüfen &amp; übernehmen</strong>'
                   . '</div>';

            // GET-Formular
            $html .= '<form method="get" action="'.$this->h($this->moduleUrl()).'">';
            $html .= '<input type="hidden" name="id" value="'.(int)($q['id'] ?? 0).'">';
            $html .= '<input type="hidden" name="scan" value="'.$this->h((string)($q['scan'] ?? 'site')).'">';
            $html .= '<input type="hidden" name="action" value="applySelected">';

            $html .= '<div class="grid">';

            foreach ($items as $it) {
                $old = (string)$it['old'];
                $new = (string)$it['new'];

                $html .= '<div class="card">'
                       .   '<h3>(PID '.$it['uid'].') '.$this->h($it['title']).'</h3>'
                       .   '<div class="body">'
                       .     '<div class="meta">'.$this->h($it['path']).'</div>'
                       .     '<label style="display:flex;gap:8px;align-items:center;margin:6px 0">'
                       .       '<input type="checkbox" name="apply[]" value="'.(int)$it['uid'].'"> Auswählen'
                       .     '</label>';

                if ($old === '') {
                    $html .= '<div style="color:#777">— Kein bestehendes Snippet gefunden.</div>';
                } else {
                    $html .= '<div class="diff">'
                           .   '<div><strong>Alt</strong><pre>'.$this->h($old).'</pre></div>'
                           .   '<div><strong>Neu</strong><pre>'.$this->h($new).'</pre></div>'
                           . '</div>';
                }

                $html .=   '<details style="margin-top:8px"><summary>Vorschlag (Raw JSON)</summary><pre>'.$this->h($new).'</pre></details>'
                       .   '</div></div>';
            }

            $html .= '</div>';
            $html .= '<div style="margin-top:14px;display:flex;gap:10px">'
                   .   '<button class="btn btn-info" type="submit">Ausgewählte übernehmen</button>'
                   .   '<a class="btn btn-default" href="'.$this->h($backUrl).'">← Zurück</a>'
                   . '</div>';
            $html .= '</form>';
            $html .= '</div></div>';

            return $this->html($html);
        } 

        // ===== Apply Selected (aus Prüfliste) =====
        if ($action === 'applySelected') {
            // Wir nutzen hier bewusst GET (wie bei den anderen Buttons im Modul),
            // deshalb KEIN harter CSRF-Check.

            $apply     = (array)($q['apply'] ?? []);
            $applyPids = array_filter(array_map('intval', $apply));

            /** @var SnippetInserter $inserter */
            $inserter  = GeneralUtility::makeInstance(SnippetInserter::class);

            $ok = 0;
            $errors = [];

            foreach ($applyPids as $pid) {
                try {
                    $json = $this->buildSuggestionJsonForPid($pid);
                    if ($json === '' || trim($json) === '{}') {
                        continue;
                    }
                    $inserter->upsert($pid, $json, null);
                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = 'PID ' . $pid . ': ' . $e->getMessage();
                }
            }

            $msg = $ok . ' Snippets übernommen.';
            if (!empty($errors)) {
                $msg .= ' Fehler: ' . implode(', ', $errors);
            }

            return new RedirectResponse(
                $this->moduleUrl([
                    'id'   => (int)($q['id'] ?? 0),
                    'scan' => (string)($q['scan'] ?? 'site'),
                    'msg'  => $msg,
                ]),
                303
            );
        } 

        // ===== Standard-UI (Scanner) =====
        $controls  = '<div style="margin:0 0 16px;display:flex;gap:8px;align-items:center">';
        $controls .= '<a class="btn btn-default" href="'.$this->h($this->moduleUrl(['id'=>(int)$id,'scan'=>'current'])).'">Scan current page</a>';
        $controls .= '<a class="btn btn-default" href="'.$this->h($this->moduleUrl(['id'=>(int)$id,'scan'=>'site'])).'">Scan whole site</a>';
        $controls .= '</div>';

        if (($q['scan'] ?? '') === 'site') {
            $controls .= '<a class="btn btn-info" href="'.$this->h($this->moduleUrl(['id' => (int)$id, 'scan' => 'site', 'action' => 'apply_all'])).'"style="margin:-8px 0 16px">Alle anwenden</a>';
        }

        $controls .= '<div style="margin:-8px 0 16px;display:flex;gap:8px">'
                   .   '<a class="btn btn-default" href="'.$this->h($this->moduleUrl(['id'=>(int)$id,'action'=>'queue'])).'">Queue öffnen</a>'
                   . '</div>';

        $msgHtml = '';
        if (!empty($q['msg'])) {
            $msgHtml = '<div class="rs-summary" style="border-color:#cfeccf;background:#f4fff4;color:#1c7c1c">'
                     . $this->h((string)$q['msg'])
                     . '</div>';
        }

        $content = '<h1>Rich Snippets – Scanner</h1>'
                 . $msgHtml
                 . $controls;

        if ($scan) {
            try {
                $items = ($scan === 'site') ? $this->scanWholeSite($id) : $this->scanSinglePage($id);
                usort($items, fn($a,$b) => strcmp($a['path'], $b['path']));
                $content .= $this->renderSummary();
                $content .= $this->renderTable($items, $q, $request);
            } catch (\Throwable $e) {
                $content .= '<div style="color:#b00">Fehler: '.$this->h($e->getMessage()).'</div>';
            }
        } else {
            $content .= '<p>Wähle eine Option oben.</p>';
        }

        return $this->html($content);
    }


    /* ========================= Scans ========================= */

    private function scanSinglePage(int $pid): array
    {
        $row = $this->getPageRow($pid);
        return $row ? $this->evaluatePages([$row]) : [];
    }

    private function scanWholeSite(int $currentPid): array
    {
        $rootPid = $this->resolveRootPid($currentPid);
        /** @var PageTreeScanner $scanner */
        $scanner = GeneralUtility::makeInstance(PageTreeScanner::class);
        $pages   = $scanner->fetchAllPages($rootPid);
        return $this->evaluatePages($pages);
    }

private function evaluatePages(array $pages): array
{
    $out = [];

    try {
        /** @var ContentAnalyzer $analyzer */
        $analyzer = GeneralUtility::makeInstance(ContentAnalyzer::class);
    } catch (\Throwable $e) {
        $this->dbg('analyzer_init_error', ['msg' => $e->getMessage()]);
        error_log('[site_richsnippets] Analyzer init error: ' . $e->getMessage());
        return $out;
    }

    try {
        /** @var \MyVendor\SiteRichSnippets\Snippet\SnippetService $snippetService */
        $snippetService = GeneralUtility::makeInstance(\MyVendor\SiteRichSnippets\Snippet\SnippetService::class);
    } catch (\Throwable $e) {
        $this->dbg('snippetservice_init_error', ['msg' => $e->getMessage()]);
        error_log('[site_richsnippets] SnippetService init error: ' . $e->getMessage());
        return $out;
    }

    foreach ($pages as $p) {
        $this->stats['scanned']++;

        $pid = (int)($p['uid'] ?? 0);
        if ($pid <= 0) {
            $this->dbg('skip_page_invalid_pid', ['row' => $p]);
            continue;
        }

        // echte PageRow bevorzugen
        $pageRow = $this->getPageRow($pid) ?? $p;

        $title = (string)($pageRow['title'] ?? '');
        $path  = $this->buildPath($pid);

        $data = [];
        $analyzeOk = true;

        // ---- Analyse (aber NICHT mehr "continue" bei Fehler) ----
        try {
            $data = $analyzer->analyzePageContents($pid);
            if (method_exists($analyzer, 'enrichHints')) {
                $data = $analyzer->enrichHints($data);
            }
        } catch (\Throwable $e) {
            $analyzeOk = false;
            $this->dbg('analyze_error', ['pid' => $pid, 'msg' => $e->getMessage()]);
            error_log('[site_richsnippets] PID ' . $pid . ': analyzePageContents() error: ' . $e->getMessage());
            // wir machen weiter mit Fallback
        }

        $jsonld = null;
        $composeOk = true;

        // ---- Compose (auch hier nicht hart abbrechen) ----
        try {
            $jsonld = $snippetService->composeGraphForPage($pageRow, $data);
        } catch (\Throwable $e) {
            $composeOk = false;
            $this->dbg('compose_error', ['pid' => $pid, 'msg' => $e->getMessage()]);
            error_log('[site_richsnippets] PID ' . $pid . ': composeGraphForPage() error: ' . $e->getMessage());
        }

        // "Vorschlag" nur zählen, wenn wirklich Inhalte da sind
        // (Wenn dein SnippetService ein @graph liefert, zählen wir nur, wenn @graph nicht leer ist)
        $isSuggestion = false;
        if (is_array($jsonld) && $jsonld !== []) {
            if (isset($jsonld['@graph']) && is_array($jsonld['@graph'])) {
                $isSuggestion = count($jsonld['@graph']) > 0;
            } else {
                // falls du nicht im Graph-Format bist
                $isSuggestion = true;
            }
        }

        if ($isSuggestion) {
            $this->stats['suggestions']++;
        }

        // Fallback, damit UI NIE leer bleibt
        if (!is_array($jsonld) || $jsonld === []) {
            $jsonld = [
                '@context' => 'https://schema.org',
                '@type'    => 'WebPage',
                'name'     => (string)($pageRow['title'] ?? ''),
            ];
        }

        $out[] = [
            'uid'        => $pid,
            'title'      => $title,
            'path'       => $path,
            'suggestion' => $jsonld,

            // optional: wenn du später im Template/Render debuggen willst
            // 'debug' => ['analyzeOk' => $analyzeOk, 'composeOk' => $composeOk],
        ];
    }

    return $out;
}


    /* ========================= Darstellung ========================= */

    private function renderSummary(): string
    {
        $sc = (int)$this->stats['scanned'];
        $sg = (int)$this->stats['suggestions'];
        return '<div class="rs-summary">'
             . '<strong>Ergebnis:</strong> '
             . '<span style="margin-right:14px">Gescannt: <b>'.$sc.'</b></span>'
             . '<span>Vorschläge: <b>'.$sg.'</b></span>'
             . '</div>';
    }

    private function renderTable(array $items, array $q, ServerRequestInterface $request): string
{
    if (empty($items)) {
        return '<p><strong>Keine Vorschläge gefunden</strong>.</p>';
    }

    $html = '<table class="rs-table"><thead><tr>'
          . '<th class="rs-col-pid">PID</th>'
          . '<th class="rs-col-path">Pfad / Seite</th>'
          . '<th>JSON-LD Vorschlag</th>'
          . '<th class="rs-col-actions">Aktionen</th>'
          . '</tr></thead><tbody>';

    $currentGroup = '';

    foreach ($items as $it) {
        $pid   = (int)($it['uid'] ?? 0);
        $path  = (string)($it['path'] ?? '');
        $title = (string)($it['title'] ?? '');

        $group = implode(' / ', array_slice(explode(' / ', $path), 0, 2));
        if ($group !== $currentGroup) {
            $currentGroup = $group;
            $html .= '<tr><td colspan="4" class="rs-group">'.$this->h($currentGroup ?: 'Root').'</td></tr>';
        }

        $json = json_encode(
            $it['suggestion'] ?? [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ) ?: '{}';

        /** @var SnippetInserter $inserter */
        $inserter   = GeneralUtility::makeInstance(SnippetInserter::class);
        $hasSnippet = $inserter->exists($pid);

        $html .= '<tr>'
               .   '<td>'.$pid.'</td>'
               .   '<td>'.$this->h($path).' / <strong>'.$this->h($title).'</strong></td>'
               .   '<td><pre class="rs-pre">'.$this->h($json).'</pre></td>'
               .   '<td class="rs-actions">';

        if ($hasSnippet) {
            $html .= '<div style="margin-bottom:8px;color:#007a00;font-weight:600">✓ Snippet vorhanden</div>';
        }

        // Open JSON (Link)
        $viewUrl = $this->moduleUrl([
            'id'     => (int)($q['id'] ?? 0),
            'scan'   => (string)($q['scan'] ?? 'site'),
            'action' => 'view',
            'pid'    => $pid,
        ]);
        $html .= '<a class="btn btn-default" href="'.$this->h($viewUrl).'" style="display:block;margin-bottom:8px">Open JSON</a>';

        // Download JSON (Link)
        $downloadUrl = $this->moduleUrl([
            'id'     => (int)($q['id'] ?? 0),
            'scan'   => (string)($q['scan'] ?? 'site'),
            'action' => 'download',
            'pid'    => $pid,
        ]);
        $html .= '<a class="btn btn-default" href="'.$this->h($downloadUrl).'" style="display:block;margin-bottom:8px">Download JSON</a>';

        // Insert (Link)
        $button = $hasSnippet ? 'Snippet aktualisieren' : 'Snippet einfügen';
        $insertLink = $this->moduleUrl([
            'id'     => (int)($q['id'] ?? 0),
            'scan'   => (string)($q['scan'] ?? 'site'),
            'action' => 'insert',
            'pid'    => $pid,
        ]);
        $html .= '<a class="btn btn-info" href="'.$this->h($insertLink).'" style="display:block;margin-top:8px">'
              .  $this->h($button)
              .  '</a>';

        // Zeile schließen
        $html .= '</td></tr>';
    }

    $html .= '</tbody></table>';

    // Prüfliste: alle öffnen (Link) -> gehört NACH der Tabelle, nicht in die foreach
    $reviewUrl = $this->moduleUrl([
        'id'     => (int)($q['id'] ?? 0),
        'scan'   => (string)($q['scan'] ?? 'site'),
        'action' => 'review',
    ]);

    foreach ($items as $it2) {
        $reviewUrl .= '&pids%5B%5D='.(int)($it2['uid'] ?? 0);
    }

    $html .= '<p style="margin-top:12px">'
          .  '<a class="btn btn-info" href="'.$this->h($reviewUrl).'">Alle in Prüfliste öffnen</a>'
          .  '</p>';

    return $html;
}



    /* ========================= JSON/DB Helper ========================= */

    private function buildSuggestionJsonForPid(int $pid): string
{
    $row = $this->getPageRow($pid);
    if (!$row) {
        return '{}';
    }

    /** @var ContentAnalyzer $analyzer */
    $analyzer = GeneralUtility::makeInstance(ContentAnalyzer::class);

    /** @var \MyVendor\SiteRichSnippets\Snippet\SnippetService $snippetService */
    $snippetService = GeneralUtility::makeInstance(\MyVendor\SiteRichSnippets\Snippet\SnippetService::class);

    $data = [];
    try {
        $data = $analyzer->analyzePageContents($pid);
        if (method_exists($analyzer, 'enrichHints')) {
            $data = $analyzer->enrichHints($data);
        }
    } catch (\Throwable $e) {
        $this->dbg('build_analyze_error', ['pid' => $pid, 'msg' => $e->getMessage()]);
        error_log('[site_richsnippets] PID ' . $pid . ': buildSuggestion analyze error: ' . $e->getMessage());
        $data = [];
    }

    try {
        $jsonld = $snippetService->composeGraphForPage($row, $data);
    } catch (\Throwable $e) {
        $this->dbg('build_compose_error', ['pid' => $pid, 'msg' => $e->getMessage()]);
        error_log('[site_richsnippets] PID ' . $pid . ': buildSuggestion compose error: ' . $e->getMessage());
        $jsonld = [];
    }

    if (empty($jsonld) || !is_array($jsonld)) {
        $jsonld = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebPage',
            'name'     => (string)($row['title'] ?? ''),
        ];
    }

    return json_encode(
        $jsonld,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    ) ?: '{}';
}


    private function fetchExistingSnippetJson(int $pid): string
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');

        $row = $qb->select('bodytext')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pid, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('CType', $qb->createNamedParameter('html')),
                $qb->expr()->like('bodytext', $qb->createNamedParameter('%application/ld+json%'))
            )
            ->orderBy('sorting', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) return '';

        $html = (string)$row['bodytext'];
        if (preg_match('~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
            return trim((string)$m[1]);
        }
        return '';
    }

    private function buildPath(int $pid): string
    {
        $rows = $this->getPathRows($pid);
        $titles = array_map(fn($r) => (string)($r['title'] ?? ''), $rows);
        array_pop($titles);
        return implode(' / ', $titles);
    }

    private function getPathRows(int $pid): array
    {
        $rows = [];
        $seen = 0;
        while ($pid > 0 && $seen < 50) {
            $row = $this->getPageRow($pid);
            if (!$row) { break; }
            $rows[] = $row;
            $pid = (int)($row['pid'] ?? 0);
            $seen++;
        }
        return array_reverse($rows);
    }

    private function getPageRow(int $pid): ?array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from('pages')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($pid, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()->fetchAssociative();

        if (!$row) { return null; }
        if ((int)$row['hidden'] === 1) { return null; }
        if ((int)$row['doktype'] >= 200) { return null; }
        return $row;
    }

    private function resolveRootPid(int $currentPid): int
    {
        $sf = GeneralUtility::makeInstance(SiteFinder::class);
        try {
            $site = $sf->getSiteByPageId($currentPid > 0 ? $currentPid : $this->getFirstExistingPageId());
            return (int)$site->getRootPageId();
        } catch (\Throwable $e) {
            $sites = $sf->getAllSites();
            return !empty($sites) ? (int)reset($sites)->getRootPageId() : 0;
        }
    }

    private function getFirstExistingPageId(): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $row = $qb->select('uid')
            ->from('pages')
            ->where($qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)))
            ->orderBy('uid','ASC')
            ->setMaxResults(1)
            ->executeQuery()->fetchAssociative();
        return (int)($row['uid'] ?? 0);
    }

    /* ========================= Utility ========================= */

    private function slugify(string $s): string
    {
        $s = preg_replace('~[^\pL\d]+~u', '-', $s);
        $s = trim((string)$s, '-');
        $s = (string)@iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        $s = preg_replace('~[^-\w]+~', '', (string)$s);
        return strtolower($s ?: 'snippet');
    }

    private function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function moduleUrl(array $params = []): string
    {
        /** @var UriBuilder $uriBuilder */
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        return (string)$uriBuilder->buildUriFromRoute('web_site_richsnippets', $params);
    }

    private function fmtTs(?int $ts): string
    {
        if (!$ts || $ts <= 0) { return '—'; }
        return date('Y-m-d H:i', $ts);
    }

    /* ========================= CSRF (v13+, harter Check) ========================= */

    private function moduleToken(ServerRequestInterface $request): string
    {
        try {
            /** @var FormProtectionFactory $factory */
            $factory = GeneralUtility::makeInstance(FormProtectionFactory::class);

            $fp = $factory->createFromRequest($request);
            if ($fp && method_exists($fp, 'generateToken')) {
                return (string)$fp->generateToken('moduleCall', 'web_site_richsnippets');
            }
        } catch (\Throwable $e) {
            $this->dbg('token_error', ['msg' => $e->getMessage()]);
        }

        return '';
    }

    private function assertValidTokenOrRedirect(ServerRequestInterface $request, array $q): ?ResponseInterface
    {
        $token = (string)($q['moduleToken'] ?? '');
        if ($token === '') {
            return new HtmlResponse('<div style="color:#b00">Ungültiger Request: Token fehlt.</div>', 403);
        }

        try {
            /** @var FormProtectionFactory $factory */
            $factory = GeneralUtility::makeInstance(FormProtectionFactory::class);
            $fp = $factory->createFromRequest($request);
            if ($fp && method_exists($fp, 'validateToken')) {
                if (!$fp->validateToken($token, 'moduleCall', 'web_site_richsnippets')) {
                    return new HtmlResponse('<div style="color:#b00">Ungültiger Token.</div>', 403);
                }
            }
        } catch (\Throwable $e) {
            return new HtmlResponse('<div style="color:#b00">Token-Validierung fehlgeschlagen: ' . $this->h($e->getMessage()) . '</div>', 403);
        }

        return null;
    }

            private function baseCss(): string
{
    return '<style>
    /* Layout only – KEIN Scroll-Management hier */

    .rs-wrap{
        padding:20px;
        font:14px/1.5 system-ui, Segoe UI, Roboto, Arial;
        box-sizing:border-box;
        max-width: 100%;
        min-height: 0;
        overflow: auto;
    }

    .rs-summary{
        margin:12px 0;
        padding:10px;
        border:1px solid #e2e2e2;
        border-radius:8px;
        background:#fafafa
    }

    .rs-table{
        width:100%;
        border-collapse:collapse;
        table-layout:fixed
    }

    .rs-table th,
    .rs-table td{
        padding:6px;
        border-bottom:1px solid #ddd;
        vertical-align:top
    }

    .rs-pre{
        white-space:pre-wrap;
        word-break:break-word;
        background:#f7f7f7;
        padding:8px;
        border:1px solid #eee;
        border-radius:6px;
        max-height:260px;
        overflow:auto
    }

    .rs-col-pid{width:70px}
    .rs-col-path{width:28%}
    .rs-col-actions{width:200px}

    .rs-actions form{display:inline}
    .rs-actions .btn{
        display:block;
        margin-bottom:8px;
        width:100%
    }

    .rs-group{
        background:#f0f4ff;
        border-top:2px solid #cfe0ff;
        border-bottom:1px solid #cfe0ff;
        padding:6px 8px;
        font-weight:600
    }
    </style>';
}

        private function dbg(string $tag, array $data = []): void
    {
        try {
            $dir = '/tmp/site_richsnippets';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }

            $line = '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' ' . json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . PHP_EOL;

            @file_put_contents($dir . '/_BOOTSTRAP.log', $line, FILE_APPEND);
        } catch (\Throwable $e) {
            // Nie das Backend crashen
        }
    }

private function html(string $bodyHtml): ResponseInterface
{
    $full = '<!doctype html><html><head><meta charset="utf-8">'
          . $this->baseCss()
          . $this->scrollFixCss()
          . $this->scrollFixJs()
          . '</head><body>'
          . '<div class="rs-wrap">' . $bodyHtml . '</div>'
          . '</body></html>';

    return new HtmlResponse($full);
}

private function scrollFixCss(): string
{
    return '<style>
        html, body { height: 100%; overflow: auto; }
        body { margin: 0; }
        .rs-wrap { min-height: 100%; overflow: auto; }
    </style>';
}

private function scrollFixJs(): string
{
    return '<script>
        (function(){
            try {
                document.documentElement.style.height = "100%";
                document.body.style.height = "100%";
                document.documentElement.style.overflow = "auto";
                document.body.style.overflow = "auto";
            } catch(e) {}
        })();
    </script>';
}
}

