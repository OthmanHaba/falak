<?php

return [
    // Debounce window for proc.apply / cron.apply after a change (bursts converge once).
    'apply_delay_seconds' => (int) env('KILN_PROCESSES_APPLY_DELAY', 2),
    'apply_timeout_seconds' => 120,
    'restart_timeout_seconds' => 300,

    // Horizon finishes running jobs before it exits; SIGKILL after this.
    'horizon_stop_timeout' => 120,

    // Octane listens on the site's app_port, else on base + (crc32(site id) % span) on 127.0.0.1.
    'octane_port_base' => 8000,
    'octane_port_span' => 1000,

    // Laravel scheduler job (`schedule:run` every minute on the leader).
    'scheduler_timeout' => 3600,

    // Periodic proc.status for crash-loop detection (minutes; 0 disables).
    'status_poll_minutes' => (int) env('KILN_PROCESSES_STATUS_POLL', 5),
    // A program in backoff with at least this many restarts (or fatal) is crash-looping.
    'crash_loop_restarts' => 5,
];
