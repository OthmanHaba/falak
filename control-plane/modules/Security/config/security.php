<?php

return [
    // Every active server is audited once per this many hours (the scheduler looks hourly).
    'audit_every_hours' => (int) env('FALAK_SECURITY_AUDIT_HOURS', 24),

    // security.audit finishes within 55 s on the agent; the command gets some slack for delivery.
    'audit_timeout' => 120,

    // security.fix: installing updates may take up to 20 minutes on the agent.
    'fix_timeout' => 1800,

    // How long fixes can be undone (the agent prunes its backups after 7 days).
    'undo_days' => 7,

    // An audit this old with no answer is given up (the agent is gone or the command was lost).
    'stale_minutes' => 30,

    // Findings kept per server: the audits behind the score history (the latest findings are always kept).
    'history_days' => 90,
];
