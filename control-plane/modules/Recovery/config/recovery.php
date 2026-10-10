<?php

return [
    /*
    | The control plane's own disaster recovery is configured on its host (falak-ctl dr setup); falak-ctl writes what
    | the panel shows to state/dr.json, mounted read-only. It holds no secrets: the bucket keys and the DR passphrase
    | never reach the containers.
    */
    'status_path' => env('FALAK_DR_STATUS_PATH', '/opt/falak/state/dr.json'),

    // The organization whose owners and admins operate this install (the banner, the dashboard item, dr.* alerts).
    // An organization id or slug; empty: the oldest organization (the one install.sh's first admin created).
    'operator_organization' => env('FALAK_DR_ORGANIZATION', ''),

    // A dismissed "set up disaster recovery" banner comes back after this many days.
    'dismiss_days' => (int) env('FALAK_DR_DISMISS_DAYS', 30),

    // dr.not_configured alerts once per this many days until DR is configured.
    'reminder_days' => (int) env('FALAK_DR_REMINDER_DAYS', 7),
];
