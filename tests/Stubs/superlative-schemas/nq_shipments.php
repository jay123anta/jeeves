<?php

/**
 * One dimension and a count. The smallest schema on which "which carrier
 * shipped the most orders" is a real question with exactly one right answer,
 * and "top 5 carriers" is a real question with several.
 */
return [
    'name' => 'Shipments',
    'description' => 'One row per shipment',
    'aliases' => ['shipments', 'carriers', 'deliveries'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_shipments',
            'description' => 'One row per shipment',
            'group_column' => 'carrier',
            'columns' => [
                'carrier' => [
                    'type' => 'varchar',
                    'description' => 'The carrier that shipped it',
                    'aliases' => ['courier', 'shipper'],
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
        ],
    ],
];
