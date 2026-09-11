<?php

/** A dataset named in the singular, as `jeeves:discover` names one from a table like Chinook's `Album`. */
return [
    'name' => 'Album',
    'description' => 'Albums',
    'connection' => null,
    'tables' => [
        'primary' => [
            'name' => 'sn_album',
            'group_column' => 'title',
            'columns' => [
                'title' => ['type' => 'varchar', 'groupable' => true, 'filterable' => true],
            ],
        ],
    ],
];
