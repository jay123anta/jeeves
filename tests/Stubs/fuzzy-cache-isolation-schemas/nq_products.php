<?php

/**
 * NQ-003 fuzzy-cache dataset-isolation fixture. See nq_orders.php -  same
 * shape and same metric NAME (`revenue`) deliberately, so a cached SQL
 * result that leaked from nq_orders would still "look" plausible as an
 * answer about nq_products instead of failing loudly on a missing column.
 * A confidently wrong number is the failure mode this fixture is built to
 * expose, not a crash.
 *
 * revenue rows (seeded in the test): 30 + 45 + 15 = 90.
 */
return [
    'name' => 'Products',
    'description' => 'Product revenue',
    'aliases' => ['products'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_products',
            'description' => 'Product revenue',
            'group_column' => 'id',
            'columns' => [
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'aggregatable' => true, 'sortable' => true],
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
