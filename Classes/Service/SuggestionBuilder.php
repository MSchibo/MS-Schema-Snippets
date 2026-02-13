<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

use MyVendor\SiteRichSnippets\Snippet\SnippetTypeRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use MyVendor\SiteRichSnippets\Snippet\SnippetService;

final class SuggestionBuilder
{
        public function build(array $pageRow, array $analyzedData): array
    {
        try {
            /** @var SnippetService $snippetService */
            $snippetService = GeneralUtility::makeInstance(SnippetService::class);

            $graph = $snippetService->composeGraphForPage($pageRow, $analyzedData);

            if ($graph !== []) {
                return $graph; // { @context, @graph: [...] }
            }
        } catch (\Throwable $e) {
            error_log('[site_richsnippets] SuggestionBuilder build() error: ' . $e->getMessage());
        }

        return $this->buildLegacy($pageRow, $analyzedData);
    }

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