<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Controller;

use MyVendor\SiteRichSnippets\Service\QueueService;
use MyVendor\SiteRichSnippets\Service\PageTreeScanner;
use MyVendor\SiteRichSnippets\Service\ContentAnalyzer;
use MyVendor\SiteRichSnippets\Snippet\SnippetService;
use MyVendor\SiteRichSnippets\Service\SnippetInserter;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use MyVendor\SiteRichSnippets\Service\QueueFactory;

final class LegacyModuleController extends ActionController
{
    /** @var QueueService */
    protected $queueService;

    /** @var PageTreeScanner */
    protected $pageTreeScanner;

    // Dependency Injection für TYPO3 11
    public function injectQueueService(QueueService $queueService): void
    {
        $this->queueService = $queueService;
    }

    public function injectPageTreeScanner(PageTreeScanner $pageTreeScanner): void
    {
        $this->pageTreeScanner = $pageTreeScanner;
    }

    // -------------------------------------------------
    // Hilfsfunktion: aktuelle Seite aus Pagetree
    // -------------------------------------------------
    protected function getCurrentPageId(): int
{
    // TYPO3 11: Argumente über Extbase Request
    if (method_exists($this->request, 'hasArgument') && $this->request->hasArgument('id')) {
        $id = (int)$this->request->getArgument('id');
        if ($id > 0) {
            return $id;
        }
    }

    // Fallback: Site Root (statt hart 1)
    try {
        $sf = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $sites = $sf->getAllSites();
        if (!empty($sites)) {
            $first = reset($sites);
            return (int)$first->getRootPageId();
        }
    } catch (\Throwable $e) {
        // ignore
    }

    return 1;
}


    // Hilfsfunktion: einzelne Page-Row holen
    protected function getPageRow(int $pid): ?array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();

        $row = $qb->select('*')
            ->from('pages')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($pid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0)
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) {
            return null;
        }
        if ((int)$row['hidden'] === 1) {
            return null;
        }
        if ((int)$row['doktype'] >= 200) {
            return null;
        }
        return $row;
    }

    protected function buildSuggestionJsonForPid(int $pid): string
{
    $row = $this->getPageRow($pid);
    if (!$row) {
        return '{}';
    }

    /** @var ContentAnalyzer $analyzer */
    $analyzer = GeneralUtility::makeInstance(ContentAnalyzer::class);

    /** @var \MyVendor\SiteRichSnippets\Snippet\SnippetService $snippetService */
    $snippetService = GeneralUtility::makeInstance(\MyVendor\SiteRichSnippets\Snippet\SnippetService::class);

    $data = $analyzer->analyzePageContents($pid);
    if (method_exists($analyzer, 'enrichHints')) {
        $data = $analyzer->enrichHints($data);
    }

    $jsonld = $snippetService->composeGraphForPage($row, $data);

    // Fallback nur wenn wirklich leer
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

    // -------------------------------------------------
    // Scanner-Ansicht (Start des Moduls)
    // -------------------------------------------------
    public function scannerAction(): void
{
    $currentPageId = $this->getCurrentPageId();

    $scan = '';
    if (method_exists($this->request, 'hasArgument') && $this->request->hasArgument('scan')) {
        $scan = (string)$this->request->getArgument('scan');
    }

    $items = [];
    $scanned = 0;

    if ($scan === 'current') {
        $pages = [['uid' => $currentPageId]];
        $items = $this->evaluatePages($pages);
        $scanned = 1;
    } elseif ($scan === 'site') {
        $pages = $this->pageTreeScanner->fetchAllPages($currentPageId);
        $scanned = count($pages);
        $items = $this->evaluatePages($pages);
    }

    $this->view->assignMultiple([
        'pageId'    => $currentPageId,
        'scan'      => $scan,
        'items'     => $items,
        'scanned'   => $scanned,
        'suggested' => count($items),
    ]);
}

public function insertAction(int $pid): void
{
    if ($pid <= 0) {
        $this->addFlashMessage('Ungültige PID.');
        $this->redirect('scanner');
        return;
    }

    // ✅ Gate: Wenn Scan für Seite deaktiviert → nix einfügen
    if (!$this->pageHasEnabledItemsGate($pid)) {
        $this->addFlashMessage('Für diese Seite ist der Snippet-Scan deaktiviert (kein aktiver Item-Record auf der Seite oder im Parent).');
        $this->redirect('scanner', null, null, ['id' => $this->getCurrentPageId()]);
        return;
    }

    $json = $this->buildSuggestionJsonForPid($pid);
    if ($json === '' || trim($json) === '{}' ) {
        $this->addFlashMessage('Kein valides Snippet erzeugt.');
        $this->redirect('scanner', null, null, ['id' => $this->getCurrentPageId()]);
        return;
    }

    /** @var SnippetInserter $inserter */
    $inserter = GeneralUtility::makeInstance(SnippetInserter::class);
    $inserter->upsert($pid, $json, null);

    $this->addFlashMessage('Snippet aktualisiert (in Seite eingefügt).');
    $this->redirect('scanner', null, null, ['id' => $this->getCurrentPageId()]);
}

    // Aktuelle Seite scannen → Vorschlag in Queue (pending)
    public function queueScanCurrentPageAction(int $pageId = 0): void
{
    if ($pageId <= 0) {
        $pageId = $this->getCurrentPageId();
    }

    // ✅ Gate
    if (!$this->pageHasEnabledItemsGate($pageId)) {
        $this->addFlashMessage('Für diese Seite ist der Snippet-Scan deaktiviert.');
        $this->redirect('queue');
        return;
    }

    $json = $this->buildSuggestionJsonForPid($pageId);
    $hash = sha1($json);

    $this->queueService->addOrUpdate(
        $pageId,
        'semi',
        $json,
        $hash,
        'manualScanCurrent'
    );

    $this->addFlashMessage('Aktuelle Seite gescannt – Eintrag in Queue (pending) gelegt.');
    $this->redirect('queue');
}

public function queueScanWholeSiteAction(int $rootPageId = 0): void
{
    if ($rootPageId <= 0) {
        $rootPageId = $this->getCurrentPageId();
    }

    $pages = $this->pageTreeScanner->fetchAllPages($rootPageId);

    $count = 0;
    foreach ($pages as $page) {
        $pid = (int)($page['uid'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        // ✅ Gate pro Seite
        if (!$this->pageHasEnabledItemsGate($pid)) {
            continue;
        }

        $json = $this->buildSuggestionJsonForPid($pid);
        $hash = sha1($json);

        $this->queueService->addOrUpdate(
            $pid,
            'semi',
            $json,
            $hash,
            'manualScanSite'
        );

        $count++;
    }

    $this->addFlashMessage($count . ' Seiten gescannt – Einträge in Queue (pending) gelegt.');
    $this->redirect('queue');
}

    // -------------------------------------------------
    // Queue-Ansicht (alle Statusgruppen)
    // -------------------------------------------------
    public function queueAction(): void
    {
        $currentPageId = $this->getCurrentPageId();

        $pending  = $this->queueService->list('pending');
        $approved = $this->queueService->list('approved');
        $rejected = $this->queueService->list('rejected');
        $done     = $this->queueService->list('done');
        $error    = $this->queueService->list('error');

        $this->view->assignMultiple([
            'pending'  => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'done'     => $done,
            'error'    => $error,
        ]);
    }

    // Detail-Anzeige eines Queue-Eintrags
    public function showAction(int $uid): void
    {
        $item = $this->queueService->get($uid);
        if (!$item) {
            $this->addFlashMessage('Eintrag wurde nicht gefunden.');
            $this->redirect('queue');
        }

        $this->view->assign('item', $item);
    }

    public function approveAction(int $uid): void
{
    $queue = QueueFactory::create();
    $queue->setStatus($uid, 'approved');

    $this->addFlashMessage('Eintrag freigegeben.');
    $this->redirect('queue');
}

public function rejectAction(int $uid): void
{
    $queue = QueueFactory::create();
    $queue->setStatus($uid, 'rejected');

    $this->addFlashMessage('Eintrag abgelehnt.');
    $this->redirect('queue');
}

    // Freigegebene Einträge verarbeiten → Snippet in die Seite einfügen
    public function processApprovedAction(): void
    {
        /** @var SnippetInserter $inserter */
        $inserter = GeneralUtility::makeInstance(SnippetInserter::class);

        $ok = 0;
        $errors = [];

        foreach ($this->queueService->popApproved(50) as $item) {
            $pid = (int)$item['page_uid'];
            $json = (string)$item['snippet_json'];

            try {
                $inserter->upsert($pid, $json, null);
                $this->queueService->setStatus((int)$item['uid'], 'done');
                $ok++;
            } catch (\Throwable $e) {
                $this->queueService->setStatus((int)$item['uid'], 'error', $e->getMessage());
                $errors[] = 'PID ' . $pid . ': ' . $e->getMessage();
            }
        }

        $msg = $ok . ' Einträge verarbeitet.';
        if (!empty($errors)) {
            $msg .= ' Fehler: ' . implode(', ', $errors);
        }

        $this->addFlashMessage($msg);
        $this->redirect('queue');
    }

    public function purgeRejectedAction(int $days = 0): void
    {
        $olderThan = 0;
        if ($days > 0) {
            $olderThan = time() - ($days * 86400);
        }

        try {
            $cnt = $this->queueService->purgeByStatus('rejected', $olderThan);

            $msg = $days > 0
                ? $cnt . ' abgelehnte Einträge gelöscht (älter als ' . $days . ' Tage).'
                : $cnt . ' abgelehnte Einträge gelöscht.';

            $this->addFlashMessage($msg);
        } catch (\Throwable $e) {
            $this->addFlashMessage('Löschen fehlgeschlagen: ' . $e->getMessage());
        }

        $this->redirect('queue');
    }

    // Undo: Snippet löschen + Queue-Eintrag zurück auf pending
    public function undoAction(int $uid): void
    {
        $item = $this->queueService->get($uid);
        if (!$item) {
            $this->addFlashMessage('Eintrag wurde nicht gefunden.');
            $this->redirect('queue');
        }

        $pid = (int)$item['page_uid'];

        /** @var SnippetInserter $inserter */
        $inserter = GeneralUtility::makeInstance(SnippetInserter::class);

        try {
            if (method_exists($inserter, 'delete')) {
                $inserter->delete($pid);
            } else {
                // Fallback: leeres JSON einfügen
                $inserter->upsert($pid, '{}', null);
            }
            $this->queueService->setStatus($uid, 'pending', 'undo');
            $this->addFlashMessage('Snippet entfernt, Eintrag wieder auf "pending" gesetzt.');
        } catch (\Throwable $e) {
            $this->addFlashMessage('Undo-Fehler: ' . $e->getMessage());
        }

        $this->redirect('queue');
    }

    // -------------------------------------------------
    // (Optional) Prüfansicht & Übernahme (Review / applySelected)
    // -------------------------------------------------
    public function reviewAction(string $pidsRaw = ''): void
{
    // 1) Fallback: hole pidsRaw aus POST/GET, falls Extbase-Argument-Mapping leer bleibt
    $request = $this->request;

    // POST Body
    $body = $request->getParsedBody();
    if (($pidsRaw === '' || $pidsRaw === null) && is_array($body)) {
        $pidsRaw = (string)($body['pidsRaw'] ?? '');
    }

    // GET Query Params (optional)
    $query = $request->getQueryParams();
    if (($pidsRaw === '' || $pidsRaw === null) && is_array($query)) {
        $pidsRaw = (string)($query['pidsRaw'] ?? '');
    }

    // 2) Parse PIDs
    $pids = array_values(array_filter(array_map(
        'intval',
        preg_split('/\s*,\s*/', (string)$pidsRaw, -1, PREG_SPLIT_NO_EMPTY)
    )));

    $items = [];

    /** @var ContentAnalyzer $analyzer */
    $analyzer = GeneralUtility::makeInstance(ContentAnalyzer::class);
    /** @var \MyVendor\SiteRichSnippets\Snippet\SnippetService $snippetService */
$snippetService = GeneralUtility::makeInstance(\MyVendor\SiteRichSnippets\Snippet\SnippetService::class);

    foreach ($pids as $pid) {
        $row = $this->getPageRow($pid);
        if (!$row) {
            continue;
        }

        $data = $analyzer->analyzePageContents($pid);
        if (method_exists($analyzer, 'enrichHints')) {
            $data = $analyzer->enrichHints($data);
        }

        $jsonld = $snippetService->composeGraphForPage($row, $data);

        $items[] = [
            'uid'   => $pid,
            'title' => (string)($row['title'] ?? ''),
            'old'   => $this->fetchExistingSnippetJson($pid),
            'new'   => json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }

    $this->view->assignMultiple([
        'items'   => $items,
        'pidsRaw' => $pidsRaw, // optional fürs Prefill im Template
    ]);
}

    public function applySelectedAction(): void
{
    // pids[] robust aus POST holen
    $body = $this->request->getParsedBody();
    $pids = [];

    if (is_array($body) && isset($body['pids'])) {
        $raw = $body['pids'];
        $pids = is_array($raw)
            ? array_values(array_filter(array_map('intval', $raw)))
            : array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY))));
    }

    if ($pids === []) {
        $this->addFlashMessage('Keine Auswahl erhalten (pids[] kam nicht an).');
        $this->redirect('review');
        return;
    }

    /** @var SnippetInserter $inserter */
    $inserter = GeneralUtility::makeInstance(SnippetInserter::class);

    $ok = 0;
    $errors = [];
    $msg = sprintf('%d Snippet(s) erfolgreich hinterlegt.', $ok);
if (!empty($errors)) {
    $msg .= ' Fehler: ' . implode(' | ', $errors);
}
$this->addFlashMessage($msg);


    foreach ($pids as $pid) {
        try {
            $json = $this->buildSuggestionJsonForPid($pid);
            if ($json === '' || trim($json) === '{}' ) {
                continue;
            }
            $inserter->upsert($pid, $json, null);
            $ok++;
        } catch (\Throwable $e) {
            $errors[] = 'PID ' . $pid . ': ' . $e->getMessage();
        }
    }

    $msg = $ok . ' Snippets übernommen.';
    if ($errors) {
        $msg .= ' Fehler: ' . implode(' | ', $errors);
    }
    $this->addFlashMessage($msg);
    $this->redirect('scanner');
}


    // Bestehendes Snippet (falls vorhanden) holen
    protected function fetchExistingSnippetJson(int $pid): string
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $row = $qb->select('bodytext')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('CType', $qb->createNamedParameter('html')),
                $qb->expr()->like('bodytext', $qb->createNamedParameter('%application/ld+json%'))
            )
            ->orderBy('sorting', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) {
            return '';
        }

        $html = (string)$row['bodytext'];
        if (preg_match('~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
            return trim((string)$m[1]);
        }
        return '';
    }

    protected function buildPath(int $pid): string
{
    // Für TYPO3 11 simpel halten (ohne Rootline API): nur Titel, sonst Fallback
    $row = $this->getPageRow($pid);
    if (!$row) {
        return (string)$pid;
    }
    return (string)($row['title'] ?? (string)$pid);
}

protected function pageHasEnabledItemsGate(int $pid): bool
{
    // Gate nur anwenden, wenn Methode existiert (bei dir ist sie ja da)
    if ($this->queueService && method_exists($this->queueService, 'pageHasEnabledItems')) {
        return (bool)$this->queueService->pageHasEnabledItems($pid);
    }
    // Wenn Methode nicht existiert: nicht blocken
    return true;
}

protected function evaluatePages(array $pages): array
{
    /** @var ContentAnalyzer $analyzer */
    $analyzer = GeneralUtility::makeInstance(ContentAnalyzer::class);

    /** @var SnippetService $snippetService */
    $snippetService = GeneralUtility::makeInstance(SnippetService::class);

    $out = [];

    foreach ($pages as $p) {
        $pid = (int)($p['uid'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        // ✅ Gate: ohne aktive Items KEIN Scan-Ergebnis anzeigen
        if (!$this->pageHasEnabledItemsGate($pid)) {
            continue;
        }

        $row = $this->getPageRow($pid);
        if (!$row) {
            continue;
        }

        $data = [];
        try {
            $data = $analyzer->analyzePageContents($pid);
            if (method_exists($analyzer, 'enrichHints')) {
                $data = $analyzer->enrichHints($data);
            }
        } catch (\Throwable $e) {
            $data = [];
        }

        $jsonld = [];
        try {
            $jsonld = $snippetService->composeGraphForPage($row, $data);
        } catch (\Throwable $e) {
            $jsonld = [];
        }

        if (empty($jsonld) || !is_array($jsonld)) {
            $jsonld = [
                '@context' => 'https://schema.org',
                '@type'    => 'WebPage',
                'name'     => (string)($row['title'] ?? ''),
            ];
        }

        $json = json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        $existing = $this->fetchExistingSnippetJson($pid);

        $out[] = [
            'pid'         => $pid,
            'title'       => (string)($row['title'] ?? ''),
            'path'        => $this->buildPath($pid),
            'json'        => $json,
            'hasExisting' => (trim($existing) !== ''),
        ];
    }

    return $out;
}
}
