<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Ps14 Component',
    'description' => 'Reusable Fluid components for TYPO3 projects based on ps14_foundation.',
    'category' => 'fe',
    'author' => 'Christian Pschorr',
    'author_email' => 'pschorr.christian@gmail.com',
    'author_company' => '',
    'state' => 'beta',
    'version' => '14.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'ps14_foundation' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
