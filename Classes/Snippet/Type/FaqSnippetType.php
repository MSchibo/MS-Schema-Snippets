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

        // *** NEU: Antwort sicher in Plain-Text wandeln ***
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

    return [
        '@type'      => 'FAQPage',
        'name'       => (string)($pageRow['title'] ?? ''),
        'mainEntity' => $questions,
    ];
}

    /**
     * Holt FAQ-Einträge aus dem Analyzer-Array.
     *
     * @param array<string,mixed> $analyzedData
     * @return array<int,array<string,string>>
     */
    private function extractItems(array $analyzedData): array
    {
        // Deine aktuelle Struktur: 'faqs' => [['q' => '...', 'a' => '...'], ...]
        if (!empty($analyzedData['faqs']) && is_array($analyzedData['faqs'])) {
            return $analyzedData['faqs'];
        }

        // Fallbacks, falls du irgendwann umbenennst
        if (!empty($analyzedData['faqItems']) && is_array($analyzedData['faqItems'])) {
            return $analyzedData['faqItems'];
        }
        if (!empty($analyzedData['faq']) && is_array($analyzedData['faq'])) {
            return $analyzedData['faq'];
        }

        return [];
    }
}