<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

use TYPO3\CMS\Core\Core\Environment;

final class SettingsService
{
    private string $file;

    /** Nur noch Runtime-Defaults (aktuell nur lastScanTs). */
    private array $defaults = [
        'lastScanTs' => 0,
    ];

    public function __construct()
    {
        $dir = Environment::getVarPath() . '/site_richsnippets';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->file = $dir . '/settings.json';
        if (!is_file($this->file)) {
            $this->write($this->defaults);
        }
    }

    /* ========================= Extension-Konfiguration ========================= */

    /**
     * Liefert die Extension-Config aus $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'].
     *
     * @return array<string,mixed>
     */
    public function getExtensionConfig(): array
    {
        return $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['site_richsnippets'] ?? [];
    }

    /**
     * Batch-Limit, das wir z.B. im Queue-Processing verwenden.
     */
    public function getBatchLimit(): int
    {
        $conf = $this->getExtensionConfig();
        return max(1, (int)($conf['batchLimit'] ?? 50));
    }

    /**
     * Globale Aktivierung der Snippet-Typen per CSV.
     * Beispiel: 'enabledTypes' => 'faq,courseList'
     *
     * Leer = alle Typen sind aktiv.
     *
     * @return string[]
     */
    public function getEnabledTypes(): array
    {
        $conf = $this->getExtensionConfig();
        $csv  = (string)($conf['enabledTypes'] ?? '');

        if ($csv === '') {
            return []; // leer = alle aktiv
        }

        return array_filter(array_map('trim', explode(',', $csv)));
    }

    /**
     * Platzhalter für spätere typ-spezifische Einstellungen.
     * Erwartet in EXTCONF z.B.:
     *
     *  'types' => [
     *      'faq' => ['maxItems' => 20],
     *      'courseList' => ['maxItems' => 10],
     *  ]
     *
     * @return array<string,mixed>
     */
    public function getTypeSettings(string $typeIdentifier): array
    {
        $conf  = $this->getExtensionConfig();
        $types = $conf['types'] ?? [];

        if (is_array($types) && isset($types[$typeIdentifier]) && is_array($types[$typeIdentifier])) {
            return $types[$typeIdentifier];
        }

        return [];
    }

    /**
     * Kompakte Sammel-Methode, falls alter Code noch "get()" erwartet.
     * (z.B. für Scheduler; kann später ganz entfernt werden, wenn nirgends mehr genutzt.)
     *
     * @return array<string,mixed>
     */
    public function get(): array
    {
        $conf = $this->getExtensionConfig();
        return [
            // aus EXTCONF
            'batchLimit' => $this->getBatchLimit(),
            // früher gab es defaultMode/autoPlan/dailyTime – hier nur noch Dummy/Kompatibilität
            'defaultMode' => (string)($conf['autoMode'] ?? 'semi'),
            'autoPlan'    => 'off',
            'dailyTime'   => '03:00',
            // aus Runtime-JSON
            'lastScanTs'  => $this->getLastScanTs(),
        ];
    }

    /**
     * Minimaler Setter für Kompatibilität – aktuell nur lastScanTs sinnvoll.
     *
     * @param array<string,mixed> $values
     */
    public function set(array $values): void
    {
        if (array_key_exists('lastScanTs', $values)) {
            $this->setLastScanTs((int)$values['lastScanTs']);
        }
        // alles andere wird über die Extension-Konfiguration gepflegt
    }

    /* ========================= Runtime (JSON-Datei) ========================= */

    public function setLastScanTs(int $ts): void
    {
        $cur = $this->read();
        $cur['lastScanTs'] = max(0, $ts);
        $this->write($cur);
    }

    public function getLastScanTs(): int
    {
        $data = $this->read();
        return (int)($data['lastScanTs'] ?? 0);
    }

    // -------- intern: JSON-Read/Write --------

    /**
     * @return array<string,mixed>
     */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return $this->defaults;
        }
        $json = (string)@file_get_contents($this->file);
        $data = json_decode($json, true);
        return is_array($data) ? $data : $this->defaults;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function write(array $data): void
    {
        $tmp = $this->file . '.tmp';
        @file_put_contents(
            $tmp,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
        @rename($tmp, $this->file);
        @chmod($this->file, 0664);
    }
}