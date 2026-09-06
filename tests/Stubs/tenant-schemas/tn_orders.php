<?php

/** A dataset carrying a tenant discriminator the package cannot scope by. NQ-002. */
return [
    'name' => 'Tenant Orders',
    'description' => 'Orders belonging to many tenants',
    'aliases' => ['tenant orders'],
    'connection' => null,
    'llm_instructions' => 'Orders across tenants.',
    'tables' => [
        'primary' => [
            'name' => 'tn_orders',
            'description' => 'Orders',
            'group_column' => 'customer_name',
            'columns' => [
                'customer_name' => ['type' => 'varchar', 'description' => 'Customer', 'groupable' => true],
                'tenant_id' => ['type' => 'integer', 'description' => 'Owning tenant', 'filterable' => true],
                'amount' => ['type' => 'decimal', 'description' => 'Amount', 'aggregatable' => true],
            ],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 100,
    'default_metric' => 'amount',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
