<?php

/**
 * A sales table with several legitimate breakdowns, which is the ordinary
 * shape: you can ask for revenue by customer, by region, or by status, and
 * only one of them can be the schema default.
 */
return [
    'name' => 'Sales',
    'description' => 'Order lines',
    'aliases' => ['sales', 'orders', 'revenue'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'gb_sales',
            'description' => 'Order lines',
            'group_column' => 'customer_name',
            'columns' => [
                'customer_name' => [
                    'type' => 'varchar',
                    'description' => 'Customer',
                    'groupable' => true,
                    'filterable' => true,
                ],
                'region' => [
                    'type' => 'varchar',
                    'description' => 'Sales region',
                    'aliases' => ['territory', 'area'],
                    'groupable' => true,
                    'filterable' => true,
                ],
                'status' => [
                    'type' => 'varchar',
                    'description' => 'Order status',
                    'groupable' => true,
                    'filterable' => true,
                ],
                'revenue' => [
                    'type' => 'decimal',
                    'description' => 'Line revenue',
                    'aliases' => ['sales', 'turnover'],
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
