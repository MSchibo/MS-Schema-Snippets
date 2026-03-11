<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet\Type;

use MyVendor\SiteRichSnippets\Snippet\SnippetTypeInterface;

final class QaSnippetType implements SnippetTypeInterface
{
    public function getIdentifier(): string
    {
        return 'qna';
    }

    public function getLabel(): string
    {
        return 'Fragen und Antworten';
    }

    public function isEnabledForPage(array $pageRow, array $analyzedData, array $settings = []): bool
    {
        $items = $this->extractItems($analyzedData);
        return !empty($items);
    }

    public function build(array $pageRow, array $analyzedData, array $settings = []): array
    {
        $items = $this->extractItems($analyzedData);
        if ($items === []) {
            return [];
        }

        $first = reset($items);
        if (!is_array($first)) {
            return [];
        }

        $question = trim((string)($first['q'] ?? $first['question'] ?? ''));
        $answer   = trim((string)($first['a'] ?? $first['answer'] ?? ''));

        if ($question === '' || $answer === '') {
            return [];
        }

        $answerPlain = trim(strip_tags($answer));
        if ($answerPlain === '') {
            return [];
        }

        $snippet = [
            '@type' => 'QAPage',
            'mainEntity' => [
                '@type' => 'Question',
                'name' => $question,
                'text' => $question,
                'answerCount' => 1,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answerPlain,
                ],
            ],
        ];

        if (!empty($pageRow['title'])) {
            $snippet['name'] = (string)$pageRow['title'];
        }

        return [$snippet];
    }

    private function extractItems(array $analyzedData): array
    {
        if (!empty($analyzedData['qna']) && is_array($analyzedData['qna'])) {
            return $analyzedData['qna'];
        }

        if (!empty($analyzedData['qa']) && is_array($analyzedData['qa'])) {
            return $analyzedData['qa'];
        }

        if (!empty($analyzedData['faqs']) && is_array($analyzedData['faqs'])) {
            return $analyzedData['faqs'];
        }

        if (!empty($analyzedData['questions']) && is_array($analyzedData['questions'])) {
            return $analyzedData['questions'];
        }

        return [];
    }
}