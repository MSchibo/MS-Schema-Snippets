<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet\Type;

use MyVendor\SiteRichSnippets\Snippet\SnippetTypeInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ProductSnippetType implements SnippetTypeInterface
{
    public function getIdentifier(): string
    {
        return 'product';
    }

    public function getLabel(): string
    {
        return 'Produkt';
    }

    public function isEnabledForPage(array $pageRow, array $analyzedData, array $settings = []): bool
    {
        $product = $this->extractProduct($analyzedData);
        return !empty($product['name']);
    }

    public function build(array $pageRow, array $analyzedData, array $settings = []): array
    {
        $product = $this->extractProduct($analyzedData);
        if (empty($product['name'])) {
            return [];
        }

        $snippet = [
            '@type' => 'Product',
            'name'  => (string)$product['name'],
        ];

        if (!empty($product['description'])) {
            $snippet['description'] = (string)$product['description'];
        }

        if (!empty($product['sku'])) {
            $snippet['sku'] = (string)$product['sku'];
        }

        if (!empty($product['brand'])) {
            $snippet['brand'] = [
                '@type' => 'Brand',
                'name'  => (string)$product['brand'],
            ];
        }

        if (!empty($product['image'])) {
            $image = $this->makeAbsoluteUrl((string)$product['image'], (int)($pageRow['uid'] ?? 0));
            if ($image !== '') {
                $snippet['image'] = $image;
            }
        }

        if (!empty($product['price'])) {
            $snippet['offers'] = [
                '@type' => 'Offer',
                'price' => (string)$product['price'],
                'priceCurrency' => !empty($product['currency']) ? (string)$product['currency'] : 'EUR',
                'availability' => 'https://schema.org/InStock',
            ];
        }

        return [$snippet];
    }

    private function extractProduct(array $analyzedData): array
    {
        if (!empty($analyzedData['product']) && is_array($analyzedData['product'])) {
            return $analyzedData['product'];
        }

        return [];
    }

    private function makeAbsoluteUrl(string $path, int $pageUid): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }

        $baseUrl = $this->detectBaseUrl($pageUid);
        if ($baseUrl === '') {
            return $path;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function detectBaseUrl(int $pageUid): string
    {
        try {
            /** @var SiteFinder $siteFinder */
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $site = $siteFinder->getSiteByPageId($pageUid);

            return rtrim((string)$site->getBase(), '/');
        } catch (\Throwable $e) {
            return '';
        }
    }
}