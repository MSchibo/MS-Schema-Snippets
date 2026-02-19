<?php
defined('TYPO3') || die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// Wichtig für TYPO3 11: Tabelle auf normalen Seiten erlauben
ExtensionManagementUtility::allowTableOnStandardPages('tx_siterichsnippets_item');

// Damit sie im "Create new record" Wizard auftauchen darf
ExtensionManagementUtility::addToInsertRecords('tx_siterichsnippets_item');
