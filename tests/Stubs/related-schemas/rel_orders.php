<?php

/** Fact table: the money lives here, the customer's name does not. */
return [
    'name' => 'Orders',
    'description' => 'Order lines',
    'aliases' => ['orders', 'sales'],
    'connection' => null,
    'llm_instructions' => 'Revenue is quantity * unit_price.',
    'tables' => [
        'primary' => [
            'name' => 'rel_orders',
            'description' => 'Order lines',
            'group_column' => 'status',
            'columns' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Customer Id'],
                'quantity' => ['type' => 'integer', 'description' => 'Units', 'aggregatable' => true, 'sortable' => true],
                'unit_price' => ['type' => 'decimal', 'description' => 'Price per unit', 'aggregatable' => true, 'sortable' => true],
                'status' => ['type' => 'varchar', 'description' => 'Status', 'filterable' => true, 'groupable' => true],
            ],
            'relationships' => [
                ['column' => 'customer_id', 'references_table' => 'rel_customers', 'references_column' => 'id'],
            ],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
