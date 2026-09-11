<?php

/**
 * Product names and categories: the kind of column a person types a name into
 * and gets slightly wrong. Both opt in with `correct_typos`; `stock` is a
 * number and does not.
 *
 * "Cable A" and "Cable B" are one edit apart, so a typo between them is a tie
 * and must not be guessed. "Mic" is short enough that only its case may be
 * corrected.
 */
return [
    'name' => 'Products',
    'description' => 'Products in stock',
    'aliases' => ['products', 'inventory'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'vc_products',
            'description' => 'One row per product',
            'group_column' => 'name',
            'columns' => [
                'name' => [
                    'type' => 'varchar',
                    'description' => 'Product name',
                    'groupable' => true,
                    'filterable' => true,
                    'correct_typos' => true,
                ],
                'category' => [
                    'type' => 'varchar',
                    'description' => 'Product category',
                    'groupable' => true,
                    'filterable' => true,
                    'correct_typos' => true,
                ],
                'stock' => [
                    'type' => 'integer',
                    'description' => 'Units in stock',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
            ],
        ],
    ],
];
