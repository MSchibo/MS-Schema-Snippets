<?php
defined('TYPO3') || die();

use MyVendor\SiteRichSnippets\Hooks\AutoSnippetHook;
use MyVendor\SiteRichSnippets\Scheduler\ScanSiteToQueueTask;
use MyVendor\SiteRichSnippets\Scheduler\ProcessApprovedQueueTask;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// 1) DataHandler-Hook
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
    AutoSnippetHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']
    ['processDatamapClass']['site_richsnippets']
    = \MyVendor\SiteRichSnippets\Hooks\AutoSnippetHook::class;


// 2) Default-Extension-Config
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['site_richsnippets'] = array_replace(
    [
        'autoMode'    => 'semi',    // manual | semi | auto
        'runStrategy' => 'onSave',  // onSave | scheduler
        'batchLimit'  => 50,
    ],
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['site_richsnippets'] ?? []
);

// 3) Scheduler-Tasks
if (ExtensionManagementUtility::isLoaded('scheduler')) {

    // a) Seiten scannen & in Queue legen
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][ScanSiteToQueueTask::class] = [
        'extension'   => 'site_richsnippets',
        'title'       => 'Rich Snippets: Seiten scannen & in Queue legen',
        'description' => 'Scans the first site tree and writes JSON-LD suggestions as pending items into the queue.',
    ];

    // b) Freigegebene Queue verarbeiten
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][ProcessApprovedQueueTask::class] = [
        'extension'   => 'site_richsnippets',
        'title'       => 'Rich Snippets: Freigegebene Queue verarbeiten',
        'description' => 'Writes approved queue items into the page and marks them as done.',
    ];
}
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['site_richsnippets'] = array_replace(
    [
        'autoMode'    => 'semi',
        'runStrategy' => 'onSave',
        'batchLimit'  => 50,
        // CSV der aktivierten Typen (leer = alle)
        'enabledTypes' => 'faq,courseList',
    ],
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['site_richsnippets'] ?? []
);

call_user_func(function () {
    /** @var \TYPO3\CMS\Core\Imaging\IconRegistry $iconRegistry */
    $iconRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
        \TYPO3\CMS\Core\Imaging\IconRegistry::class
    );

    $iconRegistry->registerIcon(
        'ms-schema-snippets-module', // <- dein Identifier
        \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
        [
            'source' => 'EXT:site_richsnippets/Resources/Public/Icons/Extension/ms-schema-ld.svg',
        ]
    );
});



