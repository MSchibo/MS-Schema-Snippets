<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

final class QueueFactory
{
    public static function create(): QueueInterface
    {
        // AP2: DB-only in allen Versionen (11–13)
        return GeneralUtility::makeInstance(DbQueueAdapter::class);
    }
}
