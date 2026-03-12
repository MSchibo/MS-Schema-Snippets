<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet\Type;

use Doctrine\DBAL\ParameterType;
use MyVendor\SiteRichSnippets\Snippet\SnippetTypeInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class BreadcrumbSnippetType implements SnippetTypeInterface
{
    public function getIdentifier(): string
    {
        return 'breadcrumb';
    }

    public function getLabel(): string
    {
        return 'Navigationspfad';
    }

    public function isEnabledForPage(array $pageRow, array $analyzedData, array $settings = []): bool
    {
        $uid = (int)($pageRow['uid'] ?? 0);
        if ($uid <= 0) {
            return false;
        }

        $rootline = $this->buildRootline($uid);
        return count($rootline) >= 1;
    }

    public function build(array $pageRow, array $analyzedData, array $settings = []): array
    {
        $uid = (int)($pageRow['uid'] ?? 0);
        if ($uid <= 0) {
            return [];
        }

        $rootline = $this->buildRootline($uid);
        if ($rootline === []) {
            return [];
        }

        $baseUrl = $this->detectBaseUrl($settings);
        $elements = [];
        $position = 1;

        foreach ($rootline as $row) {
            $name = trim((string)($row['title'] ?? ''));
            if ($name === '') {
                continue;
            }

            $item = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $name,
            ];

            $url = $this->buildPageUrl($row, $baseUrl);
            if ($url !== '') {
                $item['item'] = $url;
            }

            $elements[] = $item;
        }

        if ($elements === []) {
            return [];
        }

        return [[
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ]];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildRootline(int $pageUid): array
    {
        $rows = [];
        $seen = [];
        $current = $pageUid;

        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;

            $row = $this->getPageRow($current);
            if ($row === null) {
                break;
            }

            $rows[] = $row;
            $current = (int)($row['pid'] ?? 0);
        }

        return array_reverse($rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getPageRow(int $uid): ?array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $qb->getRestrictions()->removeAll();

        $row = $qb->select('uid', 'pid', 'title', 'slug', 'hidden', 'deleted', 'doktype')
            ->from('pages')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) {
            return null;
        }

        if ((int)($row['hidden'] ?? 0) === 1) {
            return null;
        }

        if ((int)($row['doktype'] ?? 0) >= 200) {
            return null;
        }

        return $row;
    }

    private function buildPageUrl(array $pageRow, string $baseUrl): string
    {
        $slug = trim((string)($pageRow['slug'] ?? ''));

        if ($slug === '') {
            return '';
        }

        if ($baseUrl === '') {
            return $slug;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($slug, '/');
    }

    private function detectBaseUrl(array $settings): string
    {
        if (!empty($settings['baseUrl'])) {
            return trim((string)$settings['baseUrl']);
        }

        if (!empty($GLOBALS['TYPO3_CONF_VARS']['SYS']['baseURL'])) {
            return trim((string)$GLOBALS['TYPO3_CONF_VARS']['SYS']['baseURL']);
        }

        return '';
    }
}