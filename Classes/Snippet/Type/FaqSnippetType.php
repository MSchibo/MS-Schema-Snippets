<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet\Type;

use MyVendor\SiteRichSnippets\Snippet\SnippetTypeInterface;

final class FaqSnippetType implements SnippetTypeInterface
{
    public function getIdentifier(): string
    {
        return 'faq';
    }

    public function getLabel(): string
    {
        return 'FAQ';
    }

    public function isEnabledForPage(array $pageRow, array $analyzedData, array $settings = []): bool
    {
        $items = $this->extractItems($analyzedData);
        return !empty($items);
    }

    public function build(array $pageRow, array $analyzedData, array $settings = []): array
    {
        $items = $this->extractItems($analyzedData);
        if (empty($items)) {
            return [];
        }

        $questions = [];
        foreach ($items as $item) {
            $q = trim((string)($item['q'] ?? $item['question'] ?? ''));
            $a = trim((string)($item['a'] ?? $item['answer'] ?? ''));

            if ($q === '' || $a === '') {
                continue;
            }

            $aPlain = trim(strip_tags($a));
            if ($aPlain === '') {
                continue;
            }

            $questions[] = [
                '@type' => 'Question',
                'name'  => $q,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $aPlain,
                ],
            ];
        }

        if (empty($questions)) {
            return [];
        }

        $snippet = [
            '@type'      => 'FAQPage',
            'name'       => (string)($pageRow['title'] ?? ''),
            'mainEntity' => $questions,
        ];

        return [$snippet];
    }

    /**
     * Erwartet vom Analyzer z.B.:
     * [
     *   'faq' => [
     *     ['q' => 'Frage?', 'a' => 'Antwort ...'],
     *     ...
     *   ]
     * ]
     * oder
     * [
     *   'faqs' => [...],
     *   'faqItems' => [...],
     * ]
     */
    private function extractItems(array $analyzedData): array
    {
        $candidates = [
            'faq',
            'faqs',
            'faqItems',
            'faq_items',
            'questions',
        ];

        foreach ($candidates as $key) {
            if (!empty($analyzedData[$key]) && is_array($analyzedData[$key])) {
                return $analyzedData[$key];
            }
        }

        return [];
    }
}