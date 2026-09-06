<?php

/**
 * A schema that declares its own counting metric. Counting distinct customers
 * is not counting rows, so the built-in COUNT(*) must not replace it.
 */
return [
    'name' => 'Accounts',
    'description' => 'Account activity',
    'aliases' => ['accounts'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'own_count',
            'description' => 'Account activity',
            'group_column' => 'region',
            'columns' => [
                'customer_name' => ['type' => 'varchar', 'description' => 'Customer', 'groupable' => true],
                'region' => ['type' => 'varchar', 'description' => 'Region', 'groupable' => true],
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'aggregatable' => true],
            ],
        ],
    ],
    'computed_metrics' => [
        'record_count' => [
            'expression' => 'COUNT(DISTINCT customer_name)',
            'description' => 'Number of distinct customers',
            'unit' => 'customers',
            'aliases' => ['count', 'how many'],
        ],
    ],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => 'revenue',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
