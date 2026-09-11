<?php

/** The other half of the tie: aliased "invoiced", one edit from "invoices". */
return [
    'name' => 'Purchase ledger',
    'description' => 'Amounts suppliers have invoiced us',
    'aliases' => ['invoiced'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_purchase_ledger',
            'description' => 'One row per supplier invoice received',
            'group_column' => 'supplier',
            'columns' => [
                'supplier' => [
                    'type' => 'varchar',
                    'description' => 'Who invoiced us',
                    'aliases' => [],
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
        ],
    ],
];
