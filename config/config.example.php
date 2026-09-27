<?php

/**
 * Copy this file to config/config.php and adjust it. config.php is gitignored.
 *
 * Every path must be absolute and inside the PHP profile's open_basedir.
 */

return [
    // Public URL of the public/ directory, with a trailing slash. It is used for the
    // OIDC redirect URI (<base_url>auth.php) and for the Origin check on uploads.
    'base_url' => 'https://upload.example.com/',

    // Where completed files are written. The Web Station user `http` needs write access.
    // It must not be under /volume*/homes because DSM refuses that in open_basedir.
    'destination_dir' => '/volume1/uploads',

    // Where in-progress uploads are kept. null means <destination_dir>/.upload-staging.
    // It MUST be on the same volume as destination_dir. That makes finishing an upload
    // an instant rename(), and the file inherits the shared folder's ACLs.
    'staging_dir' => null,

    // Put each user's uploads in <destination_dir>/<username>/.
    'user_subdirectory' => false,

    // Application state: sessions and logs. null means <app root>/var.
    'var_dir' => null,

    'upload' => [
        // Size of each PATCH request sent by the browser. It must stay well below the proxy's
        // request body limit (Cloudflare Free/Pro: 100 MB) and the web server's body limit.
        'chunk_size' => 32 * 1024 * 1024,
        // The server rejects any request body larger than this.
        'max_chunk_size' => 64 * 1024 * 1024,
        // Maximum total file size in bytes. 0 means unlimited, but free space is still checked.
        'max_file_size' => 0,
        // Refuse new uploads or chunks if they would leave less than this much free space.
        'min_free_space' => 1024 * 1024 * 1024,
        // Incomplete uploads that get no data for this many seconds are discarded.
        'expiry_seconds' => 48 * 3600,
        // Maximum number of unfinished uploads per user.
        'max_active_uploads_per_user' => 20,
        // Number of files the browser uploads at the same time.
        'parallel_files' => 3,
        // Longest filename in bytes. Use 143 for encrypted shared folders.
        'max_filename_bytes' => 255,
    ],

    // Synology SSO Server -> Service -> enable OIDC, then register an application.
    // Redirect URI to register: <base_url>auth.php
    'oidc' => [
        // Issuer URL: the Well-known URL without /.well-known/openid-configuration.
        'issuer' => 'https://sso.example.com/webman/sso',
        'client_id' => 'CHANGE_ME',
        'client_secret' => 'CHANGE_ME',
        // Scopes in addition to "openid".
        'scopes' => ['email'],
        // ID token claim that holds the DSM username.
        'username_claim' => 'sub',
        // Optional CA bundle (PEM) used to verify the SSO server's TLS certificate,
        // for example when it uses a self-signed certificate. TLS is always verified.
        'ca_file' => null,
        // Signing out also ends the SSO Server session (OIDC RP-initiated logout), so the next
        // sign-in asks for credentials again. The logout URL comes from the provider's discovery
        // document (end_session_endpoint), or from logout_url when that is set. Register
        // <base_url> as the post-logout redirect URI in SSO Server if it asks for one.
        'single_logout' => true,
        'logout_url' => null,
        // Only these DSM usernames may sign in. An empty list allows every user the SSO
        // server authenticates.
        'allowed_users' => [],
    ],

    'session' => [
        // Sign the user out after this many seconds without any request. Every uploaded
        // chunk counts as activity.
        'idle_timeout' => 2 * 3600,
        // Sign the user out this many seconds after login, whatever the activity.
        'absolute_timeout' => 24 * 3600,
    ],

    // Addresses allowed to set CF-Connecting-IP / X-Forwarded-For. These headers are only
    // used for log lines, never for access control. Use Cloudflare's published ranges if
    // you proxy through Cloudflare.
    'trusted_proxies' => [],
];
