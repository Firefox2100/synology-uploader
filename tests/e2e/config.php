<?php

// End-to-end test configuration (used through SYNO_UPLOADER_CONFIG).
$root = getenv('E2E_ROOT') ?: sys_get_temp_dir() . '/synoup-e2e';

return [
    'base_url' => 'http://127.0.0.1:18080/',
    'destination_dir' => $root . '/dest',
    'var_dir' => $root . '/var',
    'upload' => [
        'chunk_size' => 1024 * 1024,
        'max_chunk_size' => 2 * 1024 * 1024,
        'min_free_space' => 0,
    ],
    'oidc' => [
        'issuer' => 'http://127.0.0.1:18081',
        'client_id' => 'uploader',
        'client_secret' => 'secret',
        'scopes' => ['email'],
    ],
];
