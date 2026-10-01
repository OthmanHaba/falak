<?php

return [
    /*
     * Who may create an account on /register:
     *   open    — anyone who can reach the panel (default)
     *   invite  — only addresses with a pending organization invitation
     *   closed  — nobody; admins are created with `kiln-ctl admin create`
     * The first account of a fresh install can always register.
     */
    'registration' => env('KILN_REGISTRATION', 'open'),

    // Seconds a sign-up waits for another one to finish (sign-ups run one at a time, see Registration::exclusively).
    'registration_lock_wait' => 10,
];
