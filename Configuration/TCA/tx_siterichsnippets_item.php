<?php
declare(strict_types=1);

return [
    'ctrl' => [
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

    'security' => [
        'ignorePageTypeRestriction' => true,
    ],

    'rootLevel' => 0,
    'hideTable' => false,

    'sortby' => 'sorting',
    'searchFields' => 'type,variant,hash,config,data',
    'iconfile' => 'EXT:site_richsnippets/Resources/Public/Icons/Extension.svg',
],


    'types' => [
        '1' => [
            'showitem' =>
                'hidden, type, variant, hash,'
                . '--div--;Konfiguration, config,'
                . '--div--;Daten, data,'
                . '--div--;Meta, pid, crdate, tstamp',
        ],
    ],

    'columns' => [
        'pid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],

        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.disable',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    ['label' => '', 'value' => 1],
                ],
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
            'description' => 'Content-Hash (z.B. sha1) zur Erkennung von Änderungen.',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],

        'config' => [
            'label' => 'Konfiguration (JSON)',
            'config' => [
                'type' => 'text',
                'rows' => 12,
                'enableRichtext' => false,
                'eval' => 'trim',
            ],
        ],

        'data' => [
            'label' => 'Daten (JSON)',
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