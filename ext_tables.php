<?php
declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use MyVendor\SiteRichSnippets\Controller\LegacyModuleController;

$major = (new Typo3Version())->getMajorVersion();

if ($major <= 11) {
    ExtensionUtility::registerModule(
        'MyVendor.SiteRichSnippets',
        'web',
        'siterichsnippets',
        '',
        [
            LegacyModuleController::class => 'scanner,scanCurrentPage,scanWholeSite,queue,show,approve,reject,review,applySelected,undo,processApproved,purgeRejected',
        ],
        [
            'access' => 'user,group',
            'icon'   => 'EXT:site_richsnippets/Resources/Public/Icons/Extension/ms-schema-ld.svg',
            'labels' => 'LLL:EXT:site_richsnippets/Resources/Private/Language/locallang_mod.xlf',
        ]
    );
}
