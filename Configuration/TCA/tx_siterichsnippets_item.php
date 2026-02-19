<?php
declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

$major = (new Typo3Version())->getMajorVersion();

$ctrl = [
    'title' => 'LLL:EXT:site_richsnippets/Resources/Private/Language/locallang_db.xlf:tx_siterichsnippets_item',
    'label' => 'type',
    'label_alt' => 'variant',
    'label_alt_force' => true,

    'tstamp' => 'tstamp',
    'crdate' => 'crdate',
    'cruser_id' => 'cruser_id',

    'delete' => 'deleted',
    'enablecolumns' => [
        'disabled' => 'hidden',
    ],

    'rootLevel' => 0,
    'hideTable' => false,

    'sortby' => 'sorting',
    'searchFields' => 'type,variant,hash,config,data',
    'iconfile' => 'EXT:site_richsnippets/Resources/Public/Icons/Extension.svg',
];

// nur ab v12 setzen (T11 kann sonst zicken)
if ($major >= 12) {
    $ctrl['security'] = [
        'ignorePageTypeRestriction' => true,
    ];
}

return [
    'ctrl' => $ctrl,

    'types' => [
        '1' => [
            'showitem' =>
                'hidden, active, type, variant, hash,'
                . '--div--;Konfiguration, config,'
                . '--div--;Daten, data,'
                . '--div--;Meta, pid, crdate, tstamp',
        ],
    ],

    'columns' => [
        'pid' => [
            'config' => ['type' => 'passthrough'],
        ],

        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.disable',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    ['', 1],
                ],
                'default' => 0,
            ],
        ],

        'active' => [
            'exclude' => true,
            'label' => 'Snippets aktiv (Scan erlaubt)',
            'description' => 'Wenn deaktiviert, wird auf dieser Seite (und ggf. vererbend auf Unterseiten) kein Scan/AutoHook/Queue ausgeführt.',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    ['', 1],
                ],
                'default' => 1,
            ],
        ],

        'type' => [
            'label' => 'Snippet-Typ',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['Kursliste', 'courseList'],
                    ['FAQ', 'faq'],
                    ['Fragen & Antworten', 'qna'],
                    ['Artikel', 'article'],
                    ['Navigationspfad (Breadcrumb)', 'breadcrumb'],
                    ['Karussell', 'carousel'],
                    ['Bild-Metadaten', 'imageMetadata'],
                    ['Lokales Unternehmen', 'localBusiness'],
                    ['Organisation', 'organization'],
                    ['Produkt', 'product'],
                ],
                'default' => 'faq',
            ],
        ],

        'variant' => [
            'label' => 'Variante',
            'description' => 'Optional: z.B. "default", "minimal", "custom".',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'default' => 'default',
            ],
        ],

        'hash' => [
            'label' => 'Hash',
            'description' => 'Optional: Content-Hash zur Erkennung von Änderungen.',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],

        'config' => [
            'label' => 'Konfiguration (JSON)',
            'description' => 'Typ-spezifische Konfiguration (z.B. Feldauswahl, Mapping, Regeln).',
            'config' => [
                'type' => 'text',
                'rows' => 12,
                'enableRichtext' => false,
                'eval' => 'trim',
            ],
        ],

        'data' => [
            'label' => 'Daten (JSON)',
            'description' => 'Optional: vorberechnete Daten / Overrides pro Seite.',
            'config' => [
                'type' => 'text',
                'rows' => 18,
                'enableRichtext' => false,
                'eval' => 'trim',
            ],
        ],

        'crdate' => [
            'label' => 'Erstellt',
            'config' => [
                'type' => 'passthrough',
            ],
        ],

        'tstamp' => [
            'label' => 'Geändert',
            'config' => [
                'type' => 'passthrough',
            ],
        ],
    ],
];
