<?php

return [
    // The secret access log is kept apart from the audit log, and longer: who or what read which version, and why.
    'access_log_retention_days' => (int) env('FALAK_SECRETS_ACCESS_LOG_RETENTION_DAYS', 730),

    // Revealing a value needs the password (and a 2FA code, when enabled) confirmed within this many seconds.
    'reveal_confirm_seconds' => (int) env('FALAK_SECRETS_REVEAL_CONFIRM_SECONDS', 300),
];
