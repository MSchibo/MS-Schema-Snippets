<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet\Type;

use MyVendor\SiteRichSnippets\Snippet\SnippetTypeInterface;

final class CourseListSnippetType implements SnippetTypeInterface
{
    public function getIdentifier(): string
    {
        return 'courseList';
    }

    public function getLabel(): string
    {
        return 'Kursliste';
    }

    public function isEnabledForPage(array $pageRow, array $analyzedData, array $settings = []): bool
    {
        $courses = $this->extractCourses($analyzedData);
        return !empty($courses);
    }

public function build(array $pageRow, array $analyzedData, array $settings = []): array
{
    $courses = $this->extractCourses($analyzedData);
    if (empty($courses)) {
        return [];
    }

    $elements = [];
    $position = 1;

    $defaultProviderName = '';
    if (!empty($settings['providerName'])) {
        $defaultProviderName = trim(strip_tags((string)$settings['providerName']));
    } elseif (!empty($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'])) {
        $defaultProviderName = trim(strip_tags((string)$GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']));
    }

    foreach ($courses as $course) {
        $nameRaw   = (string)($course['name'] ?? '');
        $namePlain = trim(strip_tags($nameRaw));
        if ($namePlain === '') {
            continue;
        }

        $courseJson = [
            '@type' => 'Course',
            'name'  => $namePlain,
        ];

        if (!empty($course['description'])) {
            $descPlain = trim(strip_tags((string)$course['description']));
            if ($descPlain !== '') {
                $courseJson['description'] = $descPlain;
            }
        }

        if (!empty($course['url'])) {
            $url = trim((string)$course['url']);
            if ($url !== '') {
                $courseJson['url'] = $url;
            }
        }

        $providerRaw = (string)($course['providerName'] ?? '');
        if ($providerRaw === '' && $defaultProviderName !== '') {
            $providerRaw = $defaultProviderName;
        }

        $providerPlain = trim(strip_tags($providerRaw));
        if ($providerPlain !== '') {
            $courseJson['provider'] = [
                '@type' => 'Organization',
                'name'  => $providerPlain,
            ];
        }

        $elements[] = [
            '@type'    => 'ListItem',
            'position' => $position++,
            'item'     => $courseJson,
        ];
    }

    if (empty($elements)) {
        return [];
    }

    $snippet = [
        '@type'           => 'ItemList',
        'itemListElement' => $elements,
    ];

    return [$snippet];
}




    /**
     * Erwartet vom Analyzer z.B.:
     * [
     *   'courses' => [
     *     [
     *       'name'         => 'Kurs A',
     *       'description'  => '...',
     *       'url'          => 'https://…',
     *       'providerName' => 'ISEO Akademie'
     *     ],
     *     ...
     *   ]
     * ]
     */
    private function extractCourses(array $analyzedData): array
    {
        if (!empty($analyzedData['courses']) && is_array($analyzedData['courses'])) {
            return $analyzedData['courses'];
        }
        return [];
    }
}
