<?php

/** Composite foreign key: both columns belong to one constraint. */
return [
    'name' => 'Tenant Sales',
    'description' => 'Sales per tenant and region',
    'aliases' => ['tenant sales'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'rel_sales',
            'description' => 'Sales',
            'group_column' => 'region_code',
            'columns' => [
                'tenant_id' => ['type' => 'integer', 'description' => 'Tenant'],
                'region_code' => ['type' => 'varchar', 'description' => 'Region code', 'groupable' => true],
                'revenue' => ['type' => 'decimal', 'description' => 'Revenue', 'aggregatable' => true],
            ],
            'relationships' => [
                ['column' => 'tenant_id', 'references_table' => 'rel_regions', 'references_column' => 'tenant_id', 'constraint' => 'rel_sales_region_fkey'],
                ['column' => 'region_code', 'references_table' => 'rel_regions', 'references_column' => 'region_code', 'constraint' => 'rel_sales_region_fkey'],
            ],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
