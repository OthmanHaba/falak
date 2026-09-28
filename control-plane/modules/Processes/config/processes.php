<?php

return [
    // Debounce window for proc.apply / cron.apply after a change (bursts converge once).
    'apply_delay_seconds' => (int) env('KILN_PROCESSES_APPLY_DELAY', 2),
    'apply_timeout_seconds' => 120,
    'restart_timeout_seconds' => 300,

    // Horizon finishes running jobs before it exits; SIGKILL after this.
    'horizon_stop_timeout' => 120,

    // Octane listens on 127.0.0.1:<port> (allocated by Sites, see sites.octane_port_base). The edge proxies to it only
    // after a probe got an HTTP answer within this many seconds of a (re)start.
    'octane_probe_seconds' => 60,
    // Octane restarts on every deploy while the edge holds requests for edge.octane_try_duration_seconds (30 s). The old
    // server closes its port at SIGTERM, but its graceful shutdown is unbounded (Caddy's default grace period) and can
    // hang: SIGKILL it after this many seconds so held requests reach the new server well within the edge's window.
    'octane_stop_timeout' => 10,
    // Octane switched off: the program stops once the edge stopped proxying to it, or after this grace period.
    'octane_drain_timeout_seconds' => 600,

    // Laravel scheduler job (`schedule:run` every minute on the leader).
    'scheduler_timeout' => 3600,

    // Periodic proc.status for crash-loop detection (minutes; 0 disables).
    'status_poll_minutes' => (int) env('KILN_PROCESSES_STATUS_POLL', 5),
    // A program in backoff with at least this many restarts (or fatal) is crash-looping.
    'crash_loop_restarts' => 5,
];
