<?php

/**
 * One table discovered out of several -  what you get from
 * `jeeves:discover --table=orders`. It references sub_customers, which
 * has no schema file of its own.
 */
return [
    'name' => 'Orders',
    'description' => 'Order lines',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'sub_orders',
            'description' => 'Order lines',
            'group_column' => 'status',
            'columns' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Customer'],
                'quantity' => ['type' => 'integer', 'description' => 'Units', 'aggregatable' => true],
                'status' => ['type' => 'varchar', 'description' => 'Status', 'groupable' => true],
            ],
            'relationships' => [
                ['column' => 'customer_id', 'references_table' => 'sub_customers', 'references_column' => 'id'],
            ],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
