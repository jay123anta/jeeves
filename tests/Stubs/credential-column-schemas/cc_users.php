<?php

// A schema file as `jeeves:discover` would have written it BEFORE 3.0:
// the stock Laravel users table, credential columns and all. Discovery
// withholds these now, but a file already on disk keeps what it was given -
// which is what `audit-schema` has to notice.
return [
    'name' => 'Users',
    'description' => 'Application users',
    'aliases' => ['users', 'accounts'],
    'connection' => null,

    'tables' => [
        'primary' => [
            'name' => 'cc_users',
            'description' => 'One row per registered user',
            'group_column' => 'name',
            'columns' => [
                'id' => ['type' => 'integer', 'description' => 'Primary key'],
                'name' => ['type' => 'varchar', 'description' => 'Display name', 'groupable' => true],
                'email' => ['type' => 'varchar', 'description' => 'Email address', 'filterable' => true],
                'password' => ['type' => 'varchar', 'description' => 'Hashed password'],
                'remember_token' => ['type' => 'varchar', 'description' => 'Remember-me token'],
                'two_factor_secret' => ['type' => 'text', 'description' => 'Two factor secret'],
                'created_at' => ['type' => 'timestamp', 'description' => 'Registered at'],
            ],
        ],
    ],
];
