<?php

/**
 * A schema as `jeeves:discover` leaves it, before anyone writes a word.
 *
 * No dataset description, no aliases, undescribed columns, an aggregatable
 * column with no unit, and an `amount` that the invoices file also claims.
 * Every one of those is something introspection cannot recover and a person
 * can state in a sentence.
 */
return [
    'name' => 'Nq Orders',
    'description' => '',
    'aliases' => [],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_orders',
            'description' => '',
            'group_column' => 'id',
            'columns' => [
                'amount' => ['type' => 'decimal', 'description' => '', 'aggregatable' => true, 'sortable' => true],
                // Values declared, none described, and no required_filter: the
                // exact shape where a total silently counts rows it should not.
                'status_cd' => ['type' => 'varchar', 'description' => '', 'filterable' => true,
                    'values' => ['paid', 'pending', 'cancelled']],
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
