<?php

/**
 * The target of rel_sales' two-column foreign key. It exists so that composite
 * join rendering is exercised against a table the SqlValidator actually
 * permits -  the prompt only offers joins to whitelisted tables.
 */
return [
    'name' => 'Regions',
    'description' => 'Sales regions, keyed per tenant',
    'aliases' => ['regions', 'territories'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'rel_regions',
            'description' => 'Sales regions, keyed per tenant',
            'group_column' => 'region_name',
            'columns' => [
                'tenant_id' => [
                    'type' => 'integer',
                    'description' => 'Owning tenant',
                    'filterable' => true,
                ],
                'region_code' => [
                    'type' => 'varchar',
                    'description' => 'Short region code',
                    'filterable' => true,
                ],
                'region_name' => [
                    'type' => 'varchar',
                    'description' => 'Human-readable region name',
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
