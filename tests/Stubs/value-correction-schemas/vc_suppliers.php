<?php

/**
 * A second table whose `name` column does NOT opt in, so a typo filtered on
 * it must stay a typo - correction belongs to the columns that asked for it.
 */
return [
    'name' => 'Suppliers',
    'description' => 'Suppliers we buy from',
    'aliases' => ['suppliers', 'vendors'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'vc_suppliers',
            'description' => 'One row per supplier',
            'group_column' => 'name',
            'columns' => [
                'name' => [
                    'type' => 'varchar',
                    'description' => 'Supplier name',
                    'groupable' => true,
                    'filterable' => true,
                ],
                'rating' => [
                    'type' => 'integer',
                    'description' => 'Rating out of five',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
            ],
        ],
    ],
];
