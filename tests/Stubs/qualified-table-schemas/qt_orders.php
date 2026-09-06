<?php

/**
 * A dataset declared the way `jeeves:discover` writes one on Postgres.
 *
 * PostgresIntrospector reports `schema.table`, and discover stores that
 * verbatim as `tables.primary.name`. Meanwhile SqlValidator and
 * SchemaRegistry::allowsTable() deliberately accept a BARE reference against a
 * qualified whitelist entry, because a model routinely omits the qualifier.
 *
 * So a rule keyed by searching the SQL text for "main.qt_orders" never matched
 * `FROM qt_orders`, and `required_filter` silently switched off on the exact
 * platform this package was extracted from -  per-scheme PostgreSQL.
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
            'name' => 'main.qt_orders',
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
