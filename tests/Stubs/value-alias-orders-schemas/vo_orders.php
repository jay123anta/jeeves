<?php

/**
 * The same mechanism in a domain with no districts in it: an order status
 * with a US spelling and a synonym, and a country known by several names -
 * including a two-letter one, because value aliases are exact matches and
 * carry none of the short-word risk that edit distance does.
 */
return [
    'name' => 'Orders',
    'description' => 'Customer orders',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'vo_orders',
            'description' => 'One row per order',
            'group_column' => 'status',
            'columns' => [
                'status' => [
                    'type' => 'varchar',
                    'description' => 'Order status',
                    'groupable' => true,
                    'filterable' => true,
                    'value_aliases' => [
                        'cancelled' => ['canceled', 'void'],
                    ],
                ],
                'country' => [
                    'type' => 'varchar',
                    'description' => 'Delivery country',
                    'groupable' => true,
                    'filterable' => true,
                    'value_aliases' => [
                        'United Kingdom' => ['UK', 'Great Britain', 'Britain'],
                    ],
                ],
                'total' => [
                    'type' => 'decimal',
                    'description' => 'Order value',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
            ],
        ],
    ],
];
