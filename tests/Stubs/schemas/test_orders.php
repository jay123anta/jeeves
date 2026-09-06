<?php

return [
    'name' => 'Test Orders',
    'description' => 'Test schema for unit tests',
    'aliases' => ['orders', 'sales', 'revenue'],
    'connection' => null,
    'llm_instructions' => 'Test orders table with customer_name, amount, status columns.',

    'tables' => [
        'primary' => [
            'name' => 'public.orders',
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
                    'aliases' => ['revenue', 'total', 'sales'],
                    'aggregatable' => true,
                    'sortable' => true,
                ],
                'status' => [
                    'type' => 'varchar',
                    'description' => 'Order status',
                    'filterable' => true,
                ],
            ],
        ],
    ],

    'computed_metrics' => [
        'avg_amount' => [
            'expression' => 'ROUND(AVG(amount), 2)',
            'description' => 'Average order amount',
            'unit' => '$',
            'aliases' => ['average'],
        ],
    ],

    'example_queries' => [
        ['natural' => 'Top 10 customers', 'sql' => 'SELECT customer_name, SUM(amount) AS total FROM public.orders GROUP BY customer_name ORDER BY total DESC LIMIT 10'],
    ],

    'max_limit' => 100,
    'default_metric' => 'amount',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
