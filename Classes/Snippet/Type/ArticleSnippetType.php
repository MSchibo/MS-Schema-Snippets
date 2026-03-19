<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet\Type;

use MyVendor\SiteRichSnippets\Snippet\SnippetTypeInterface;

final class ArticleSnippetType implements SnippetTypeInterface
{
    public function getIdentifier(): string
    {
        return 'article';
    }

    public function getLabel(): string
    {
        return 'Artikel';
    }

    public function isEnabledForPage(array $pageRow, array $analyzedData, array $settings = []): bool
    {
        return !empty($analyzedData['paragraphs']);
    }

    public function build(array $pageRow, array $analyzedData, array $settings = []): array
    {
        $title = trim((string)($pageRow['title'] ?? ''));
        if ($title === '') {
            return [];
        }

        $paragraphs = $analyzedData['paragraphs'] ?? [];
        if (empty($paragraphs)) {
            return [];
        }

        $description = $this->buildDescription($paragraphs);

        $snippet = [
            '@type' => 'Article',
            'headline' => $title,
            'description' => $description,
        ];

        return [$snippet];
    }

    private function buildDescription(array $paragraphs): string
    {
        $text = implode(' ', array_slice($paragraphs, 0, 2));
        return mb_substr(trim($text), 0, 250);
    }
}