<?php

/**
 * A dataset with a business rule the schema cannot imply.
 *
 * Cancelled orders exist in the table and must never count towards a total.
 * Nothing in the column types, names or foreign keys says so -  it is knowledge
 * only the business has, which is exactly what `required_filter` is for.
 *
 * Seeded revenue: 100 (paid) + 200 (paid) + 500 (cancelled) = 800 raw,
 * 300 once the rule is applied.
 */
return [
    'name' => 'Orders',
    'description' => 'Customer orders',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_orders',
            'description' => 'Orders',
            'group_column' => 'id',
            'required_filter' => "status != 'cancelled'",
            'columns' => [
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'unit' => '₹', 'aggregatable' => true, 'sortable' => true],
                'status' => ['type' => 'varchar', 'description' => 'Order status', 'filterable' => true, 'groupable' => true],
            ],
            'relationships' => [],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
