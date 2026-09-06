<?php

/**
 * A schema shaped the way `jeeves:discover` actually writes one.
 *
 * Every column here is something introspection can recover: name, type,
 * nullability. There is no `values` key, because discover has never written
 * one and its merge deletes a hand-added one unless it is curated -  which is
 * why the audit's required_filter check, which read only `values`, could never
 * fire on the documented discover → audit → curate loop.
 *
 * `deleted_at` with no required_filter is the near-certain gap: those rows are
 * in the table and nothing tells the model to leave them out of a total.
 */
return [
    'name' => 'Orders',
    'description' => 'Customer orders',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'sd_orders',
            'description' => 'Orders',
            'group_column' => 'region',
            'columns' => [
                'region' => ['type' => 'varchar', 'description' => 'Sales region', 'groupable' => true, 'filterable' => true],
                'revenue' => ['type' => 'decimal', 'description' => 'Order revenue', 'unit' => '₹', 'aggregatable' => true, 'sortable' => true],
                'deleted_at' => ['type' => 'timestamp', 'description' => 'Soft-delete timestamp', 'filterable' => true],
            ],
            'relationships' => [],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => 'revenue',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
