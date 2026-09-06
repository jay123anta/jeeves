<?php

/** A second dataset that also offers `amount`, so the term is ambiguous. */
return [
    'name' => 'Nq Invoices',
    'description' => 'Invoices raised',
    'aliases' => ['invoices'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_invoices',
            'description' => 'Invoices',
            'group_column' => 'id',
            'columns' => [
                'amount' => ['type' => 'decimal', 'description' => 'Invoice amount', 'unit' => '₹', 'aggregatable' => true],
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
