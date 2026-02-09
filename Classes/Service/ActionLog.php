<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

use TYPO3\CMS\Core\Core\Environment;

final class ActionLog
{
    private string $file;

    public function __construct()
    {
        $dir = Environment::getVarPath() . '/site_richsnippets';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $this->file = $dir . '/audit.jsonl';
        if (!is_file($this->file)) { @touch($this->file); }
    }

    /** protokolliert eine Auto-Anwendung und gibt Audit-ID zurück */
    public function logApply(int $pageUid, ?string $oldJson, string $newJson): int
    {
        $rows = $this->readAll();
        $uid  = $this->nextUid($rows);
        $rows[] = [
            'uid'        => $uid,
            'page_uid'   => $pageUid,
            'type'       => 'auto_apply',
            'old_json'   => ($oldJson ?? ''),
            'new_json'   => $newJson,
            'created_at' => time(),
        ];
        $this->writeAll($rows);
        return $uid;
    }

    /** letzte N Einträge (neueste zuerst) */
    public function listRecent(int $limit = 20): array
    {
        $all = $this->readAll();
        usort($all, fn($a,$b) => ($b['created_at']??0) <=> ($a['created_at']??0));
        return array_slice($all, 0, max(1, $limit));
    }

    public function get(int $uid): ?array
    {
        foreach ($this->readAll() as $r) {
            if ((int)($r['uid'] ?? 0) === $uid) { return $r; }
        }
        return null;
    }

    /** entfernt einen Audit-Eintrag (z. B. nach Undo) */
    public function delete(int $uid): void
    {
        $all = array_values(array_filter($this->readAll(), fn($r) => (int)($r['uid'] ?? 0) !== $uid));
        $this->writeAll($all);
    }

    // --- intern ---
    private function readAll(): array
    {
        $out = [];
        $h = @fopen($this->file, 'r');
        if ($h) {
            while (($line = fgets($h)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row)) { $out[] = $row; }
            }
            fclose($h);
        }
        return $out;
    }

    private function writeAll(array $rows): void
    {
        $tmp = $this->file . '.tmp';
        $h = fopen($tmp, 'w');
        foreach ($rows as $r) {
            fwrite($h, json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n");
        }
        fclose($h);
        @rename($tmp, $this->file);
    }

    private function nextUid(array $rows): int
    {
        $max = 0;
        foreach ($rows as $r) { $max = max($max, (int)($r['uid'] ?? 0)); }
        return $max + 1;
    }
}
