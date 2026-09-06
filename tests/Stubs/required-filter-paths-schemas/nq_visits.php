<?php

/**
 * A neighbouring dataset with NO required_filter, which is the control.
 *
 * The rule written about `nq_orders` says nothing about this table, so a query
 * here must be served. The guard used to be keyed on the dataset the model
 * reported rather than on the tables the SQL names, which made it both too
 * weak and too strong: a mislabelled response turned it off, and a correct
 * query routed under the orders dataset was refused for omitting a filter that
 * does not apply to it.
 */
return [
    'name' => 'Visits',
    'description' => 'Site visits',
    'aliases' => ['visits'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_visits',
            'description' => 'Visits',
            'group_column' => 'id',
            'columns' => [
                'source' => ['type' => 'varchar', 'description' => 'Referral source', 'groupable' => true, 'filterable' => true],
                // A counter column named after the NEIGHBOURING table. Ordinary
                // design on a summary table, and it used to arm that table's
                // required_filter: the rule was matched by searching the SQL
                // text, which cannot tell a column from a table. The refusal
                // then quoted a filter on `status`, a column this table does
                // not have, and was marked unretriable.
                'nq_orders' => ['type' => 'integer', 'description' => 'Orders attributed', 'aggregatable' => true, 'sortable' => true],
                'revenue' => ['type' => 'decimal', 'description' => 'Attributed revenue', 'unit' => '₹', 'aggregatable' => true, 'sortable' => true],
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
