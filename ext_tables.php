<?php
declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use MyVendor\SiteRichSnippets\Controller\LegacyModuleController;

ExtensionManagementUtility::addToInsertRecords('tx_siterichsnippets_item');

if (method_exists(ExtensionManagementUtility::class, 'allowTableOnStandardPages')) {
    ExtensionManagementUtility::allowTableOnStandardPages('tx_siterichsnippets_item');
}

$major = (new Typo3Version())->getMajorVersion();

if ($major <= 11) {
    ExtensionUtility::registerModule(
        'MyVendor.SiteRichSnippets',
        'web',
        'siterichsnippets',
        '',
        [
            LegacyModuleController::class => 'scanner,insert,queue,show,approve,reject,undo,processApproved,purgeRejected,queueScanCurrentPage,queueScanWholeSite,review,applySelected',
        ],
        [
            'access' => 'user,group',
            'icon'   => 'EXT:site_richsnippets/Resources/Public/Icons/Extension/ms-schema-ld.svg',
            'labels' => 'LLL:EXT:site_richsnippets/Resources/Private/Language/locallang_mod.xlf',
        ]
    );
}