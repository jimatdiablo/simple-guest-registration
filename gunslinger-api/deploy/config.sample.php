<?php

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'userAccounts',
        'user' => 'db_user',
        'pass' => 'db_pass',
    ],
    'auth' => [
        'username' => 'api_user',
        'password' => 'replace-with-api-password',
    ],
    // Safety guardrails: only these SG values can be read/updated by these APIs.
    'allowed_refresh_sgs' => [10],
    'allowed_update_sgs' => [10],
    'max_refresh_limit' => 5000,
    'default_refresh_limit' => 5000,
];
