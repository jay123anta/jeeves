<?php

/**
 * A schema whose SUM metric is aliased with an ordinary business word.
 *
 * This is the shape `jeeves:discover --ai` writes for almost any orders
 * table: a total, expressed as SUM(), carrying the words users say for it.
 * It exists because that shape used to disarm the non_sum_aggregate guard for
 * every question containing one of those words - "what is the highest
 * revenue" named a metric the schema provides, so the bypass fired, and
 * SqlBuilder answered it with SUM().
 */
return [
    'name' => 'Sum Alias Orders',
    'description' => 'Orders whose SUM metric is aliased "revenue"',
    'aliases' => ['sa orders'],
    'connection' => null,
    'llm_instructions' => 'Orders with amount and status.',

    'tables' => [
        'primary' => [
            'name' => 'sa_orders',
            'description' => 'Orders table',
            'group_column' => 'customer_name',
            'columns' => [
                'customer_name' => [
                    'type' => 'varchar',
                    'description' => 'Customer name',
                    'filterable' => true,
                    'groupable' => true,
                ],
                'amount' => [
                    'type' => 'decimal',
                    'description' => 'Order amount',
                    'unit' => '$',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
            ],
        ],
    ],

    'computed_metrics' => [
        // The SUM, wearing the words a user would say. No AVG, no MAX.
        'total_amount' => [
            'expression' => 'SUM(amount)',
            'description' => 'Total order value',
            'unit' => '$',
            'aliases' => ['revenue', 'total', 'sales'],
        ],
    ],

    'example_queries' => [],

    'max_limit' => 100,
    'default_metric' => 'amount',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
