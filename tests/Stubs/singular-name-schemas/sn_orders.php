<?php

/** COUNTERWEIGHT. A name already plural stays as it is - not "orderses". */
return [
    'name' => 'Orders',
    'description' => 'Orders',
    'connection' => null,
    'tables' => [
        'primary' => [
            'name' => 'sn_orders',
            'group_column' => 'status',
            'columns' => [
                'status' => ['type' => 'varchar', 'groupable' => true, 'filterable' => true],
            ],
        ],
    ],
];
