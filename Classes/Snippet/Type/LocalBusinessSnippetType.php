<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet\Type;

use MyVendor\SiteRichSnippets\Snippet\SnippetTypeInterface;

final class LocalBusinessSnippetType implements SnippetTypeInterface
{
    public function getIdentifier(): string
    {
        return 'localbusiness';
    }

    public function getLabel(): string
    {
        return 'Lokales Unternehmen';
    }

    public function isEnabledForPage(array $pageRow, array $analyzedData, array $settings = []): bool
    {
        $org = $this->extractBusiness($analyzedData);

        return !empty($org['name']) || !empty($org['telephone']) || !empty($org['email']);
    }

    public function build(array $pageRow, array $analyzedData, array $settings = []): array
    {
        $org = $this->extractBusiness($analyzedData);
        if ($org === []) {
            return [];
        }

        $name = trim((string)($org['name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($pageRow['title'] ?? ''));
        }

        if ($name === '') {
            return [];
        }

        $snippet = [
            '@type' => 'LocalBusiness',
            'name' => $name,
        ];

        $description = trim((string)($org['description'] ?? ''));
        if ($description !== '') {
            $snippet['description'] = $description;
        }

        $telephone = trim((string)($org['telephone'] ?? ''));
        if ($telephone !== '') {
            $snippet['telephone'] = $telephone;
        }

        $email = trim((string)($org['email'] ?? ''));
        if ($email !== '') {
            $snippet['email'] = $email;
        }

        $url = trim((string)($org['url'] ?? ''));
        if ($url !== '') {
            $snippet['url'] = $url;
        }

        return [$snippet];
    }

    private function extractBusiness(array $analyzedData): array
    {
        return !empty($analyzedData['org']) && is_array($analyzedData['org'])
            ? $analyzedData['org']
            : [];
    }
}