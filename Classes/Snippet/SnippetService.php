<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet;

use MyVendor\SiteRichSnippets\Service\SettingsService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SnippetService
{
    private SnippetTypeRegistry $registry;
    private SettingsService $settingsService;

    public function __construct(
        ?SnippetTypeRegistry $registry = null,
        ?SettingsService $settingsService = null
    ) {
        // TYPO3 11–13 kompatibel: fallback via makeInstance
        $this->registry = $registry ?? GeneralUtility::makeInstance(SnippetTypeRegistry::class);
        $this->settingsService = $settingsService ?? GeneralUtility::makeInstance(SettingsService::class);
    }

    public function composeForPage(array $pageRow, array $analyzedData): array
    {
        $types = $this->registry->getTypesForPage($pageRow, $analyzedData);

        $items = [];
        foreach ($types as $type) {
            $typeSettings = $this->settingsService->getTypeSettings($type->getIdentifier());
            $builtItems = $type->build($pageRow, $analyzedData, $typeSettings);

        // Falls ein Type aus Versehen ein einzelnes Snippet (assoc array) zurückgibt:
            if ($builtItems !== [] && $this->isAssocArray($builtItems)) {
                $builtItems = [$builtItems];
        }

            foreach ($builtItems as $item) {
                if (is_array($item) && $item !== []) {
                    $items[] = $item;
            }
        }
    }

        return $this->normalizeAndDedupe($items);
    }

    private function isAssocArray(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    public function composeGraphForPage(array $pageRow, array $analyzedData): array
    {
        $items = $this->composeForPage($pageRow, $analyzedData);
        if ($items === []) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $items,
        ];
    }

    private function normalizeAndDedupe(array $items): array
    {
        $seenIds = [];
        $seenSingletonTypes = [];

        $singletonTypes = [
            'Organization',
            'WebSite',
            'WebPage',
            'BreadcrumbList',
            'FAQPage'
        ];

        $out = [];

        foreach ($items as $item) {

            if (!empty($item['@id'])) {
                if (isset($seenIds[$item['@id']])) {
                    continue;
                }
                $seenIds[$item['@id']] = true;
            } elseif (!empty($item['@type']) && in_array($item['@type'], $singletonTypes, true)) {
                if (isset($seenSingletonTypes[$item['@type']])) {
                    continue;
                }
                $seenSingletonTypes[$item['@type']] = true;
            }

            $out[] = $item;
        }

        return $out;
    }
}