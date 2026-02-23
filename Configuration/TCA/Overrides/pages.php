<?php
defined('TYPO3') || die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

if (method_exists(ExtensionManagementUtility::class, 'allowTableOnStandardPages')) {
    ExtensionManagementUtility::allowTableOnStandardPages('tx_siterichsnippets_item');
}

ExtensionManagementUtility::addToInsertRecords('tx_siterichsnippets_item');