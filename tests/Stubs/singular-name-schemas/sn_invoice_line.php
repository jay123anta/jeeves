<?php

/** A two-word singular name: only the last word takes the plural. */
return [
    'name' => 'Invoice Line',
    'description' => 'Invoice lines',
    'connection' => null,
    'tables' => [
        'primary' => [
            'name' => 'sn_invoice_line',
            'group_column' => 'item',
            'columns' => [
                'item' => ['type' => 'varchar', 'groupable' => true, 'filterable' => true],
            ],
        ],
    ],
];
