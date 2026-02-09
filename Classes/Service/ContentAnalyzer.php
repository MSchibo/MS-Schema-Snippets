<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ContentAnalyzer
{
    private function clean(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function plainText(string $html, string $allowed = '<br><br/><ul><ol><li><p>'): string
    {
        $text = strip_tags($html, $allowed);
        $text = preg_replace('~\s+~u', ' ', $text ?? '');
        return trim((string)$text);
    }

    /**
     * Liefert eine strukturierte Analyse des sichtbaren Seiteninhalts.
     * @return array{
     *   headings:array<int,string>,
     *   paragraphs:array<int,string>,
     *   faqs:array<int,array{q:string,a:string}>,
     *   steps:array<int,string>,
     *   productHints:array<int,bool>,
     *   jobHints:array<int,bool>,
     *   eventHints:array<int,bool>
     * }
     */
    public function analyzePageContents(int $pageId): array
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        $qb = $conn->createQueryBuilder();
        $rows = $qb->select('uid','CType','header','subheader','bodytext','list_type','pi_flexform')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageId, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->eq('hidden',  $qb->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $headings=[]; $paragraphs=[]; $faqs=[]; $steps=[];
        $productHints=[]; $jobHints=[]; $eventHints=[]; $courses = [];

        foreach ($rows as $r) {
            $bodyRaw = (string)($r['bodytext'] ?? '');
            $headerRaw = (string)($r['header'] ?? '');
            $ctype = (string)$r['CType'];
            $ltype  = (string)($r['list_type'] ?? '');
            $uid    = (int)$r['uid'];
            $header = $this->clean($headerRaw);
            $body   = $this->clean($bodyRaw);

            if ($header !== '') { $headings[] = $header; }
            if ($body   !== '') { $paragraphs[] = $body; }
            
            // JSON-LD Script-Elemente ignorieren
            if (
                stripos($bodyRaw, 'application/ld+json') !== false ||
                stripos($headerRaw, 'JSON-LD (Rich Snippet)') !== false
            ) {
                continue;
            }

            // ====== FAQ/Accordion-Erkennung ======
            $isAccordion = (bool)preg_match('~faq|accordion|toggle~i', $ctype . ' ' . $ltype)
                        || in_array($ctype, ['site_playground_accordion','site_accordion','bootstrap_package_accordion'], true);

            if ($isAccordion) {
                // 1) FlexForm-Parsing (wenn vorhanden)
                $faqs = array_merge($faqs, $this->extractFaqFromFlexFormOrHtml($r));

                // 2) Falls nichts in FlexForm gefunden → HTML-Heuristiken
                if (!empty($r['bodytext'])) {
                    $faqs = array_merge($faqs, $this->extractFaqFromHtml((string)$r['bodytext']));
                }
            }

                        // ====== Kurs-Erkennung (MVP für Phase 1) ======
            $haystack = $header . ' ' . $body;
            if (preg_match('~\b(Kurs|Course|Schulung|Training|Seminar)\b~i', $haystack)) {
                $courseName = $header !== '' ? $header : $this->firstSentence($bodyRaw);
                if ($courseName !== '') {
                    $courses[] = [
                        'name'         => $courseName,
                        'description'  => $this->plainText($bodyRaw),
                        // URL und Provider kannst du später in Phase 2 verfeinern
                        'url'          => '',
                        'providerName' => '',
                    ];
                }
            }


            // ====== HowTo/Steps-Erkennung ======
            if (preg_match('~howto|steps?|schritte~i', $ctype . ' ' . $ltype)) {
                if (!empty($r['bodytext'])) {
                    foreach (preg_split('/\r?\n+/u', (string)$r['bodytext']) as $line) {
                        $t = $this->clean($line);
                        if ($t !== '') { $steps[] = $t; }
                    }
                }
            }

            $hay = $ctype.' '.$header.' '.$body;
            if (preg_match('~\b(product|produkt|sku|preis|price)\b~i', $hay)) { $productHints[] = true; }
            if (preg_match('~\b(job|stelle|ausschreibung|bewerbung|vollzeit|teilzeit)\b~i', $hay)) { $jobHints[] = true; }
            if (preg_match('~\b(event|veranstaltung|termin|beginn|start|datum)\b~i', $hay)) { $eventHints[] = true; }

            // ====== Fallback: Normale Text-CEs als Q/A interpretieren ======
            if (!$isAccordion && in_array($ctype, ['text','textmedia','html','list','textpic'], true)) {
                if ($header) {
                    $first = $this->firstSentence((string)$r['bodytext']);
                    if ($first !== '') {
                        $faqs[] = ['q' => $header, 'a' => $this->plainText($first)];
                    }
                }
            }

            // ====== Beispiel: IRRE-Items unseres Test-CE auslesen (falls genutzt) ======
            if ($ctype === 'site_playground_accordion') {
                $faqs = array_merge($faqs, $this->extractPlaygroundIrre($uid));
            }
        }

        return [
            'headings'     => array_values(array_unique(array_filter($headings))),
            'paragraphs'   => array_values(array_filter($paragraphs)),
            'faqs'         => $this->uniqueFaqs($faqs),
            'steps'        => $steps,
            'productHints' => $productHints,
            'jobHints'     => $jobHints,
            'eventHints'   => $eventHints,
            'courses'      => $courses
        ];
    }

    private function firstSentence(string $html): string
    {
        $s = $this->plainText($html);
        if ($s === '') { return ''; }
        if (preg_match('~(.+?[.!?])(\s|$)~u', $s, $m)) {
            return trim((string)$m[1]);
        }
        return $s;
    }

    /**
     * Versucht zuerst FlexForm (XML) zu parsen, sonst HTML-Analyse.
     */
    private function extractFaqFromFlexFormOrHtml(array $row): array
    {
        $out = [];

        // FlexForm?
        $xml = (string)($row['pi_flexform'] ?? '');
        if ($xml !== '') {
            try {
                $parsed = @simplexml_load_string($xml);
                if ($parsed) {
                    $json = json_decode(json_encode($parsed), true);
                    if (is_array($json)) {
                        $this->walkAndCollectQA($json, $out);
                        if (!empty($out)) {
                            return $out;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // FlexForm-Parse fehlgeschlagen, ignorieren
            }
        }

        // HTML-Fallback
        $out = array_merge($out, $this->extractFaqFromHtml((string)($row['bodytext'] ?? '')));

        // Fallback: Header/Body
        $h = $this->clean((string)($row['header'] ?? ''));
        $b = $this->plainText((string)($row['bodytext'] ?? ''));
        if ($h !== '' && $b !== '') {
            $out[] = ['q' => $h, 'a' => $b];
        }

        return $out;
    }

    /**
     * Sucht Q/A-Paare in häufigen Accordion-Markups.
     */
    private function extractFaqFromHtml(string $html): array
    {
        $out = [];
        if ($html === '') { return $out; }

        // 1) Definition List
        if (preg_match_all('~<dt[^>]*>(.*?)</dt>\s*<dd[^>]*>(.*?)</dd>~is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $q = $this->clean((string)$hit[1]);
                $a = $this->plainText((string)$hit[2]);
                if ($q !== '' && $a !== '') { $out[] = ['q'=>$q,'a'=>$a]; }
            }
        }

        // 2) Headline + Paragraph (h2/h3 + p)
        if (preg_match_all('~<(h2|h3)[^>]*>(.*?)</\1>\s*<p[^>]*>(.*?)</p>~is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $q = $this->clean((string)$hit[2]);
                $a = $this->plainText((string)$hit[3]);
                if ($q !== '' && $a !== '') { $out[] = ['q'=>$q,'a'=>$a]; }
            }
        }

        // 3) Button + Panel (Bootstrap/ARIA-like)
        if (preg_match_all('~<button[^>]*>(.*?)</button>\s*<div[^>]*>(.*?)</div>~is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $q = $this->clean((string)$hit[1]);
                $a = $this->plainText((string)$hit[2]);
                if ($q !== '' && $a !== '') { $out[] = ['q'=>$q,'a'=>$a]; }
            }
        }

        // 4) Generischer „Frage/Antwort"-Block
        if (preg_match_all('~(?:Frage|Question)\s*[:\-]\s*(.+?)\s+(?:Antwort|Answer)\s*[:\-]\s*(.+?)(?:$|\R\R)~isu', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $q = $this->clean((string)$hit[1]);
                $a = $this->plainText((string)$hit[2]);
                if ($q !== '' && $a !== '') { $out[] = ['q'=>$q,'a'=>$a]; }
            }
        }

        return $out;
    }

    /**
     * Projekt-spezifisches Beispiel: IRRE-Items unseres Test-CEs
     */
    private function extractPlaygroundIrre(int $parentContentUid): array
    {
        $out = [];
        $table = 'tx_sitepg_accordion_item';
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);

        try {
            $items = $conn->select(
                ['question','answer','hidden','deleted'],
                $table,
                ['parent' => $parentContentUid],
                [],
                ['sorting' => 'ASC']
            )->fetchAllAssociative();
        } catch (\Throwable $e) {
            return $out;
        }

        foreach ($items as $it) {
            if ((int)($it['deleted'] ?? 0) === 1) { continue; }
            if ((int)($it['hidden']  ?? 0) === 1) { continue; }
            $q = $this->clean((string)$it['question']);
            $a = $this->plainText((string)$it['answer']);
            if ($q !== '' && $a !== '') {
                $out[] = ['q'=>$q, 'a'=>$a];
            }
        }
        return $out;
    }

    /**
     * DFS über ein FlexForm-Array mit Rekursionsschutz.
     * WICHTIG: Maximale Tiefe begrenzen!
     */
    private function walkAndCollectQA(array $node, array &$out, array $acc = [], int $depth = 0): void
    {
        // KRITISCH: Rekursionsschutz
        if ($depth > 20) {
            return;
        }

        foreach ($node as $k => $v) {
            $key = is_string($k) ? strtolower($k) : '';
            
            if (is_array($v)) {
                $tmp = $acc;

                if (in_array($key, ['question','frage','q','title','header'], true)) {
                    $tmp['q'] = $this->clean($this->firstScalar($v));
                }
                if (in_array($key, ['answer','antwort','a','text','body'], true)) {
                    $tmp['a'] = $this->plainText($this->firstScalar($v));
                }

                if (!empty($tmp['q']) && !empty($tmp['a'])) {
                    $out[] = ['q'=>$tmp['q'], 'a'=>$tmp['a']];
                    $tmp = []; 
                }

                // WICHTIG: Depth + 1 beim rekursiven Aufruf
                $this->walkAndCollectQA($v, $out, $tmp ?: $acc, $depth + 1);
            } else {
                if (in_array($key, ['question','frage','q','title','header'], true)) {
                    $acc['q'] = $this->clean((string)$v);
                }
                if (in_array($key, ['answer','antwort','a','text','body'], true)) {
                    $acc['a'] = $this->plainText((string)$v);
                }
                if (!empty($acc['q']) && !empty($acc['a'])) {
                    $out[] = ['q'=>$acc['q'], 'a'=>$acc['a']];
                    $acc = [];
                }
            }
        }
    }

    /** Nimmt das erste skalare Element aus einem verschachtelten Array */
    private function firstScalar(array $arr): string
    {
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveArrayIterator($arr),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $v) {
                if (is_scalar($v) && $v !== '') { 
                    return (string)$v; 
                }
            }
        } catch (\Throwable $e) {
            // Fehler beim Iterieren → leer zurückgeben
        }
        return '';
    }

    /** Dedupliziert Q/A-Paare */
    private function uniqueFaqs(array $faqs): array
    {
        $seen = [];
        $out  = [];
        foreach ($faqs as $fa) {
            $key = md5(mb_strtolower(($fa['q'] ?? '').'|'.($fa['a'] ?? ''), 'UTF-8'));
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = $fa;
        }
        return $out;
    }

    /**
     * Ergänzt erweiterte Hints für zusätzliche Schema-Typen.
     */
    public function enrichHints(array $analyzed): array
    {
        $hints = [
            'faq' => !empty($analyzed['faqs']),
            'howto' => !empty($analyzed['steps']),
            'product' => false,
            'job' => false,
            'event' => false,
            'organization' => false,
            'software' => false,
            'course' => false,
        ];

        $product = []; $event = []; $org = []; $software = []; $course = [];

        foreach (array_merge($analyzed['headings'], $analyzed['paragraphs']) as $text) {
            // Produkt
            if (preg_match('/\b(Produkt|Product|SKU|Preis|€|EUR)\b/i', $text)) {
                $hints['product'] = true;
                if (preg_match('/(?:€|\bEUR\b)\s*([0-9]+(?:[.,][0-9]{2})?)/u', $text, $m)) {
                    $product['price'] = strtr($m[1], ',', '.');
                    $product['currency'] = 'EUR';
                }
            }

            // Job
            if (preg_match('/\b(Job|Stelle|Ausschreibung|Vollzeit|Teilzeit|Bewerbung)\b/i', $text)) {
                $hints['job'] = true;
            }

            // Event
            if (preg_match('/\b(Event|Veranstaltung|Termin|Datum|Beginn)\b/i', $text)) {
                $hints['event'] = true;
                if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) {
                    $event['start'] = $m[1];
                }
            }

            // Organization
            if (preg_match('/(Firma|Unternehmen|Organisation|Kontakt|Adresse|Telefon|Impressum)/i', $text)) {
                $hints['organization'] = true;
                if (preg_match('/\b(?:Tel\.?|Telefon)\s*[:\-]?\s*([+0-9\/\s\-]+)/i', $text, $m)) {
                    $org['phone'] = trim($m[1]);
                }
            }

            // Software/App
            if (preg_match('/(Software|App|Download|Version|Windows|macOS|Linux|Android|iOS)/i', $text)) {
                $hints['software'] = true;
            }

            // Course
            if (preg_match('/(Kurs|Schulung|Training|Seminar)/i', $text)) {
                $hints['course'] = true;
            }
        }

        $analyzed['hints'] = $hints;
        $analyzed['product'] = $product;
        $analyzed['event'] = $event;
        $analyzed['org'] = $org;
        $analyzed['software'] = $software;
        $analyzed['course'] = $course;

        return $analyzed;
    }
}