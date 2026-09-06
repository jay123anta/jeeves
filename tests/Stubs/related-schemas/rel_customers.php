<?php

/** Dimension table: the name lives here, the money does not. */
return [
    'name' => 'Customers',
    'description' => 'People who buy from us',
    'aliases' => ['customers', 'clients'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'rel_customers',
            'description' => 'Customers',
            'group_column' => 'name',
            'columns' => [
                'name' => ['type' => 'varchar', 'description' => 'Customer name', 'filterable' => true, 'groupable' => true],
                'region' => ['type' => 'varchar', 'description' => 'Region', 'filterable' => true, 'groupable' => true],
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
