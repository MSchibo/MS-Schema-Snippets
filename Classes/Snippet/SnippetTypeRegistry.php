<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet;

use MyVendor\SiteRichSnippets\Service\SettingsService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SnippetTypeRegistry
{
    /**
     * Alle Klassen, die es aktuell gibt.
     * (später einfach erweitern um weitere Typen)
     */
    private const TYPE_CLASSES = [
    \MyVendor\SiteRichSnippets\Snippet\Type\CourseListSnippetType::class,
    \MyVendor\SiteRichSnippets\Snippet\Type\FaqSnippetType::class,
];

    /** @var SnippetTypeInterface[]|null */
    private ?array $instances = null;

    /**
     * Liefert alle registrierten Typen (instanziiert).
     */
    public function getAllTypes(): array
    {
        if ($this->instances !== null) {
            return $this->instances;
        }

        $list = [];
        foreach (self::TYPE_CLASSES as $className) {
            /** @var SnippetTypeInterface $obj */
            $obj = GeneralUtility::makeInstance($className);
            $list[$obj->getIdentifier()] = $obj;
        }

        $this->instances = $list;
        return $this->instances;
    }

    /**
     * Liefert nur die Typen, die global aktiv sind UND
     * für diese Seite sinnvoll sind (isEnabledForPage).
     */
    public function getTypesForPage(array $pageRow, array $analyzedData): array
{
    /** @var SettingsService $settingsService */
    $settingsService = GeneralUtility::makeInstance(SettingsService::class);

    // Globale Filterung (Extension-Konfiguration)
    $enabledGlobal = $settingsService->getEnabledTypes(); // [] = alle
    $enabledGlobal = $this->normalizeTypeList($enabledGlobal);

    // Page-spezifische Filterung (tx_siterichsnippets_item inkl. Vererbung)
    $pid = (int)($pageRow['uid'] ?? 0);

    /** @var \MyVendor\SiteRichSnippets\Service\QueueService $qs */
    $qs = GeneralUtility::makeInstance(\MyVendor\SiteRichSnippets\Service\QueueService::class);

    $enabledForPage = $this->normalizeTypeList($qs->resolveEnabledTypesForPage($pid));

    // WICHTIG:
    // Leere Page-Liste bedeutet NICHT mehr "alles blockieren",
    // sondern "kein zusätzlicher Page-Filter".
    $usePageFilter = ($enabledForPage !== []);

    $out = [];
    foreach ($this->getAllTypes() as $identifier => $type) {
        $identifier = $this->normalizeType((string)$identifier);

        // 1) Global
        if ($enabledGlobal !== [] && !in_array($identifier, $enabledGlobal, true)) {
            continue;
        }

        // 2) Pro Seite nur filtern, wenn wirklich etwas konfiguriert wurde
        if ($usePageFilter && !in_array($identifier, $enabledForPage, true)) {
            continue;
        }

        $typeSettings = $settingsService->getTypeSettings($identifier);

        // 3) Type eigene Heuristik
        if ($type->isEnabledForPage($pageRow, $analyzedData, $typeSettings)) {
            $out[] = $type;
        }
    }

    return $out;
}

private function normalizeTypeList($list): array
{
    // akzeptiert: [] | CSV-string | array
    if (is_string($list)) {
        $list = preg_split('/\s*,\s*/', trim($list), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    if (!is_array($list)) {
        return [];
    }

    $out = [];
    foreach ($list as $v) {
        $t = $this->normalizeType((string)$v);
        if ($t !== '') {
            $out[] = $t;
        }
    }
    return array_values(array_unique($out));
}

private function normalizeType(string $id): string
{
    $id = strtolower(trim($id));

    // Aliase (falls irgendwo camelCase reinläuft)
    $map = [
        'course'     => 'courselist',
        'course_list'=> 'courselist',
        'courselist' => 'courselist',
        'faqpage'    => 'faq',
    ];

    return $map[$id] ?? $id;
}
}
