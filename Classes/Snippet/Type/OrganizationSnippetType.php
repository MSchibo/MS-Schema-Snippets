<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet\Type;

use MyVendor\SiteRichSnippets\Snippet\SnippetTypeInterface;

final class OrganizationSnippetType implements SnippetTypeInterface
{
    public function getIdentifier(): string
    {
        return 'organization';
    }

    public function getLabel(): string
    {
        return 'Organisation';
    }

    public function isEnabledForPage(array $pageRow, array $analyzedData, array $settings = []): bool
    {
        if (!empty($analyzedData['hints']['organization'])) {
            return true;
        }

        $org = $analyzedData['org'] ?? [];
        if (!is_array($org)) {
            return false;
        }

        return !empty($org['name'])
            || !empty($org['description'])
            || !empty($org['email'])
            || !empty($org['telephone']);
    }

    public function build(array $pageRow, array $analyzedData, array $settings = []): array
    {
        $orgData = $analyzedData['org'] ?? [];
        if (!is_array($orgData)) {
            $orgData = [];
        }

        $name = trim((string)($orgData['name'] ?? ''));
        if ($name === '' && !empty($settings['name'])) {
            $name = trim(strip_tags((string)$settings['name']));
        }
        if ($name === '' && !empty($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'])) {
            $name = trim(strip_tags((string)$GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']));
        }

        $description = trim((string)($orgData['description'] ?? ''));
        if ($description === '' && !empty($settings['description'])) {
            $description = trim(strip_tags((string)$settings['description']));
        }

        $email = trim((string)($orgData['email'] ?? ''));
        if ($email === '' && !empty($settings['email'])) {
            $email = trim((string)$settings['email']);
        }

        $telephone = trim((string)($orgData['telephone'] ?? ''));
        if ($telephone === '' && !empty($settings['telephone'])) {
            $telephone = trim((string)$settings['telephone']);
        }

        $url = trim((string)($orgData['url'] ?? ''));
        if ($url === '' && !empty($settings['url'])) {
            $url = trim((string)$settings['url']);
        }

        if ($name === '' && $description === '' && $email === '' && $telephone === '') {
            return [];
        }

        $snippet = [
            '@type' => 'Organization',
        ];

        if ($name !== '') {
            $snippet['name'] = $name;
        }

        if ($description !== '') {
            $snippet['description'] = $description;
        }

        if ($email !== '') {
            $snippet['email'] = $email;
        }

        if ($telephone !== '') {
            $snippet['telephone'] = $telephone;
        }

        if ($url !== '') {
            $snippet['url'] = $url;
        }

        return [$snippet];
    }
}