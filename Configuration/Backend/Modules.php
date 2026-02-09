<?php
declare(strict_types=1);

use MyVendor\SiteRichSnippets\Backend\ModuleBootstrap;

return [
    'web_site_richsnippets' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user,group',
        'workspaces' => 'live',
        'path' => '/module/web/site-richsnippets',
        'iconIdentifier' => 'ms-schema-snippets-module',
        'labels' => 'LLL:EXT:site_richsnippets/Resources/Private/Language/locallang_mod.xlf',
        'navigationComponentId' => 'typo3-pagetree',
        'routes' => [
            '_default' => [
                'target' => ModuleBootstrap::class,
            ],
        ],
    ],
];
