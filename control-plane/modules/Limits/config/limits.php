<?php

return [
    // Limits services get when their own leave a value unset, by environment. Production: none (only what the user
    // sets). Other environments (staging, previews): modest caps, so one runaway branch can't starve a shared server.
    'defaults' => [
        'production' => [],
        'non_production' => array_filter([
            'memory_limit' => (int) env('FALAK_LIMITS_NONPROD_MEMORY_MB', 512),
            'cpus' => (float) env('FALAK_LIMITS_NONPROD_CPUS', 1),
            'pids_limit' => (int) env('FALAK_LIMITS_NONPROD_PIDS', 512),
            'log_max_size' => (int) env('FALAK_LIMITS_NONPROD_LOG_MB', 20),
            'log_max_files' => (int) env('FALAK_LIMITS_NONPROD_LOG_FILES', 3),
        ]),
        // Pull request previews: small, many of them share a server.
        'preview' => array_filter([
            'memory_limit' => (int) env('FALAK_LIMITS_PREVIEW_MEMORY_MB', 384),
            'cpus' => (float) env('FALAK_LIMITS_PREVIEW_CPUS', 0.5),
            'pids_limit' => (int) env('FALAK_LIMITS_PREVIEW_PIDS', 256),
            'log_max_size' => 10,
            'log_max_files' => 2,
        ]),
        // Previews of pull requests from forks run untrusted code: the strictest caps.
        'fork_preview' => array_filter([
            'memory_limit' => (int) env('FALAK_LIMITS_FORK_PREVIEW_MEMORY_MB', 256),
            'cpus' => (float) env('FALAK_LIMITS_FORK_PREVIEW_CPUS', 0.25),
            'pids_limit' => 128,
            'log_max_size' => 5,
            'log_max_files' => 2,
        ]),
    ],

    // A hard memory limit throttles (MemoryHigh) at this fraction of itself before the OOM killer acts.
    'memory_high_ratio' => 0.9,

    // oom_score_adj sent for the "protect" OOM preference (the host's OOM killer picks these last).
    'oom_protect_score' => -500,

    // A service restarting this many times within the window raises ServiceRestartLoop (once per window).
    'restart_loop' => [
        'restarts' => (int) env('FALAK_LIMITS_RESTART_LOOP', 5),
        'window_minutes' => 60,
    ],

    // How long an OOM kill shows as a badge on the service's card.
    'oom_badge_hours' => 24,
];
