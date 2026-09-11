<?php

/**
 * One of two datasets whose aliases sit one edit apart ("invoices" and
 * "invoiced"), so a question that misspells either lands exactly between them.
 * Also carries a four-letter alias, "bill", to prove short words are never
 * fuzzed - "bills" is one edit from it and must still place nothing.
 */
return [
    'name' => 'Sales invoices',
    'description' => 'Invoices raised to customers',
    'aliases' => ['invoices', 'bill'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_sales_invoices',
            'description' => 'One row per invoice raised',
            'group_column' => 'customer',
            'columns' => [
                'customer' => [
                    'type' => 'varchar',
                    'description' => 'Who was invoiced',
                    'aliases' => [],
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
        ],
    ],
];
