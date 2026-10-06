<?php

return [
    /*
     * Who may create an account on /register:
     *   open    — anyone who can reach the panel (default)
     *   invite  — only addresses with a pending organization invitation
     *   closed  — nobody; admins are created with `falak-ctl admin create`
     * The first account of a fresh install can always register.
     */
    'registration' => env('FALAK_REGISTRATION', 'open'),

    // Sensitive actions (API tokens, revealing secrets) need the password, plus the 2FA code when enabled,
    // confirmed within this many seconds.
    'reauthenticate_seconds' => (int) env('FALAK_REAUTHENTICATE_SECONDS', 300),

    // Seconds a sign-up waits for another one to finish (sign-ups run one at a time, see Registration::exclusively).
    'registration_lock_wait' => 10,
];
