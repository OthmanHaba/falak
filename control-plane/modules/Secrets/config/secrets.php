<?php

return [
    // The secret access log is kept apart from the audit log, and longer: who or what read which version, and why.
    'access_log_retention_days' => (int) env('FALAK_SECRETS_ACCESS_LOG_RETENTION_DAYS', 730),

    // Revealing a value needs the password (and a 2FA code, when enabled) confirmed within this many seconds.
    'reveal_confirm_seconds' => (int) env('FALAK_SECRETS_REVEAL_CONFIRM_SECONDS', 300),

    'providers' => [
        // Organizations may mark a provider "allow private network" (a self-hosted Vault / Infisical on the LAN).
        // Turn off on a multi-tenant instance whose private network must stay out of reach.
        'allow_private_network' => (bool) env('FALAK_SECRETS_PROVIDERS_ALLOW_PRIVATE', true),

        // AWS providers may use the control plane's own EC2 instance profile (IMDSv2). Off by default: every
        // organization of the instance would read with the control plane's role.
        'allow_instance_profile' => (bool) env('FALAK_SECRETS_PROVIDERS_ALLOW_INSTANCE_PROFILE', false),

        'retry_delay_ms' => (int) env('FALAK_SECRETS_PROVIDERS_RETRY_DELAY_MS', 200),
    ],
];
