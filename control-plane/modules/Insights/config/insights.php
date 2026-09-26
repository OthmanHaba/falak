<?php

return [
    // Occurrences, per-minute aggregates and heartbeat runs older than this are pruned daily.
    // Issues themselves (and their counters) are kept.
    'retention_days' => (int) env('KILN_INSIGHTS_RETENTION_DAYS', 30),

    // Rows deleted per statement while pruning (keeps locks and WAL bursts small).
    'prune_chunk' => 5000,

    'fingerprint' => [
        // Number of top in-app frames that identify an exception (with its normalized type).
        'frames' => 3,
    ],

    'limits' => [
        'message_bytes' => 4096,
        'stacktrace_bytes' => 16384,
        'name_bytes' => 1000,
    ],

    'heartbeats' => [
        // Seconds after the expected run before a missing heartbeat counts as missed (per monitor override).
        'grace_seconds' => (int) env('KILN_INSIGHTS_HEARTBEAT_GRACE', 120),
    ],

    'thresholds' => [
        // At most this many distinct names (routes, jobs…) may breach one threshold per evaluation.
        'max_breaches_per_evaluation' => 25,
    ],
];
