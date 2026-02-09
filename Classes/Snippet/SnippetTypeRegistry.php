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
        \MyVendor\SiteRichSnippets\Snippet\Type\FaqSnippetType::class,
        \MyVendor\SiteRichSnippets\Snippet\Type\CourseListSnippetType::class,
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

        $enabled = $settingsService->getEnabledTypes(); // z.B. ['faq','courseList'] oder [] = alle

        $out = [];
        foreach ($this->getAllTypes() as $identifier => $type) {
            // Globale Filterung (Extension-Konfiguration)
            if ($enabled !== [] && !in_array($identifier, $enabled, true)) {
                continue;
            }

            $typeSettings = $settingsService->getTypeSettings($identifier);

            if ($type->isEnabledForPage($pageRow, $analyzedData, $typeSettings)) {
                $out[] = $type;
            }
        }

        return $out;
    }
}
