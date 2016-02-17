<?php

$tempColumns = [
    'tx_directmailsubscription_localgender' => [
        'exclude' => 1,
        'label'   => 'LLL:EXT:direct_mail_subscription/locallang_db.xml:tt_address.tx_directmailsubscription_localgender',
        'config'  => [
            'type' => 'input',
            'size' => '30',
            'eval' => 'trim',
        ]
    ],

];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns(
    'tt_address',
    $tempColumns,
    1
);
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'tt_address',
    'tx_directmailsubscription_localgender',
    '',
    'after:gender'
);
