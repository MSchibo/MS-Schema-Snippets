<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

use MyVendor\SiteRichSnippets\Snippet\SnippetTypeRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SuggestionBuilder
{
    /**
     * Baut das komplette JSON-LD für eine Seite.
     */
    public function build(array $pageRow, array $analyzedData): array
{
    // Fallback: falls Registry kaputt ist -> einfache WebPage
    try {
        /** @var SnippetTypeRegistry $registry */
        $registry = GeneralUtility::makeInstance(SnippetTypeRegistry::class);
    } catch (\Throwable $e) {
        error_log('[site_richsnippets] Registry error: '.$e->getMessage());
        return $this->buildLegacy($pageRow, $analyzedData);
    }

    try {
        $types = $registry->getTypesForPage($pageRow, $analyzedData);

        if (!is_array($types)) {
            error_log('[site_richsnippets] getTypesForPage returned non-array');
            return $this->buildLegacy($pageRow, $analyzedData);
        }
    } catch (\Throwable $e) {
        error_log('[site_richsnippets] getTypesForPage error: '.$e->getMessage());
        return $this->buildLegacy($pageRow, $analyzedData);
    }

    // Alle Kandidaten sammeln: [identifier => snippet-array]
    $candidates = [];

    foreach ($types as $type) {
        try {
            if (!method_exists($type, 'build') || !method_exists($type, 'getIdentifier')) {
                error_log('[site_richsnippets] Invalid type object');
                continue;
            }

            $id      = (string)$type->getIdentifier();
            $snippet = $type->build($pageRow, $analyzedData, []);

            if (!empty($snippet)) {
                $candidates[$id] = $snippet;
            }
        } catch (\Throwable $e) {
            $identifier = method_exists($type, 'getIdentifier')
                ? $type->getIdentifier()
                : get_class($type);
            error_log('[site_richsnippets] build snippet ('.$identifier.') error: '.$e->getMessage());
            continue;
        }
    }

// Kein Typ hat etwas geliefert -> Fallback nur WebPage
if (empty($candidates)) {
    return $this->buildLegacy($pageRow, $analyzedData);
}

// Analyse-Daten anschauen
$hasFaqs    = !empty($analyzedData['faqs']);
$hasCourses = !empty($analyzedData['courses']);

$mainId = null;

// 1) Wenn Kurse erkannt wurden → immer courseList bevorzugen
if ($hasCourses && isset($candidates['courseList'])) {
    $mainId = 'courseList';

// 2) Sonst, wenn FAQs erkannt → faq
} elseif ($hasFaqs && isset($candidates['faq'])) {
    $mainId = 'faq';
}

// 3) Fallback: irgendeinen vorhandenen Typ nehmen
if ($mainId === null) {
    $mainId = array_key_first($candidates);
}

$mainSnippet = $candidates[$mainId];

// Kein @graph, klarer Root-Typ (FAQPage oder ItemList)
return [
    '@context' => 'https://schema.org',
] + $mainSnippet;
}


    /**
     * Sehr einfacher Fallback (nur WebPage).
     */
    private function buildLegacy(array $pageRow, array $analyzedData): array
    {
        try {
            $base = $this->buildBaseWebPage($pageRow, $analyzedData);

            return [
                '@context' => 'https://schema.org',
            ] + $base;
        } catch (\Throwable $e) {
            error_log('[site_richsnippets] buildLegacy error: '.$e->getMessage());
            // Absoluter Notfall-Fallback
            return [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => 'Error generating schema'
            ];
        }
    }

    private function buildBaseWebPage(array $pageRow, array $analyzedData): array
    {
        $url = $analyzedData['pageUrl'] ?? null;

        $out = [
            '@type' => 'WebPage',
            'name'  => (string)($pageRow['title'] ?? ''),
        ];

        if (!empty($url)) {
            $out['url'] = (string)$url;
        }

        return $out;
    }
}