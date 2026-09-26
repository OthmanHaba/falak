<?php

return [
    'github' => [
        // OAuth app (user tokens). Callback: <app>/source-control/callback/github
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'url' => env('GITHUB_URL', 'https://github.com'),
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        // GitHub App (installation tokens). Setup URL: <app>/source-control/github-app/setup
        'app' => [
            'id' => env('GITHUB_APP_ID'),
            'slug' => env('GITHUB_APP_SLUG'),
            'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        ],
    ],

    'gitlab' => [
        // OAuth application on gitlab.com or a self-hosted instance. Callback: <app>/source-control/callback/gitlab
        'client_id' => env('GITLAB_CLIENT_ID'),
        'client_secret' => env('GITLAB_CLIENT_SECRET'),
        'url' => env('GITLAB_URL', 'https://gitlab.com'),
    ],

    'bitbucket' => [
        // OAuth consumer. Callback: <app>/source-control/callback/bitbucket
        'client_id' => env('BITBUCKET_CLIENT_ID'),
        'client_secret' => env('BITBUCKET_CLIENT_SECRET'),
        'url' => env('BITBUCKET_URL', 'https://bitbucket.org'),
        'api_url' => env('BITBUCKET_API_URL', 'https://api.bitbucket.org/2.0'),
    ],

    // Public base URL providers post webhooks to (defaults to APP_URL).
    'webhook_url' => env('KILN_WEBHOOK_URL'),

    // Webhook deliveries accepted per minute per webhook.
    'webhook_rate_limit' => (int) env('KILN_WEBHOOK_RATE_LIMIT', 120),

    // known_hosts lines handed to builders for well-known git hosts.
    'known_hosts' => [
        'github.com' => 'github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl',
        'gitlab.com' => 'gitlab.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAfuCHKVTjquxvt6CM6tdG4SLp1Btn/nOeHHE5UOzRdf',
    ],

    // Upper bound on pages fetched when listing repositories / branches.
    'max_pages' => 10,
];
