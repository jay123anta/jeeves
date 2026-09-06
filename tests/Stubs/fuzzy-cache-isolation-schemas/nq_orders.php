<?php

/**
 * NQ-003 fuzzy-cache dataset-isolation fixture. Deliberately unrelated to
 * nq_products -  no foreign key either way, no shared `query_routing`, no
 * `prompts.max_chars` set by the tests that use this pair -  so nothing
 * outside the cache itself can be the reason a cross-dataset answer gets
 * refused. If a question ends up answered from the wrong table here, the
 * cache is the only thing that could have done it.
 *
 * revenue rows (seeded in the test): 100 + 200 + 50 = 350.
 */
return [
    'name' => 'Orders',
    'description' => 'Order revenue',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_orders',
            'description' => 'Order revenue',
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
