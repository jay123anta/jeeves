<?php

/**
 * A sales table with an explicit date column, plus a second date-like column
 * that must NOT be picked: which one a period applies to is the schema's
 * decision, not a guess from the question.
 */
return [
    'name' => 'Sales',
    'description' => 'Order lines',
    'aliases' => ['sales', 'orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'tf_sales',
            'description' => 'Order lines',
            'group_column' => 'region',
            'date_column' => 'order_date',
            'columns' => [
                'region' => [
                    'type' => 'varchar',
                    'description' => 'Sales region',
                    'groupable' => true,
                    'filterable' => true,
                ],
                'order_date' => [
                    'type' => 'date',
                    'description' => 'When the order was placed',
                    'filterable' => true,
                    'sortable' => true,
                ],
                'shipped_at' => [
                    'type' => 'date',
                    'description' => 'When it shipped',
                    'filterable' => true,
                ],
                'revenue' => [
                    'type' => 'decimal',
                    'description' => 'Line revenue',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
            ],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => 'revenue',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
