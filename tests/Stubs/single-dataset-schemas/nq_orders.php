<?php

/**
 * ONE dataset, on purpose.
 *
 * resolveAskingDataset() returns the sole registered key unconditionally when
 * only one dataset exists, which is what made findInCache()'s old bypass fire
 * on every question of a single-dataset install -  the commonest shape there
 * is. A custom QueryCacheInterface was therefore never read, while store()
 * kept working, so the cache filled up and returned nothing.
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
                // Unit included: this stub stands in for a CURATED schema, and
                // jeeves:audit-schema reports an aggregatable column with
                // no unit -  correctly, since the answer would render 1500 bare
                // with nothing saying whether that is currency or a count.
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'unit' => '₹', 'aggregatable' => true, 'sortable' => true],
                // Something to narrow on, so a test can check that a filtered
                // answer describes itself as filtered.
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
