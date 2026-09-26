<?php

return [
    // Seconds without keystrokes/output before the agent (and the sweeper, as a fallback) closes a session.
    'idle_timeout' => (int) env('KILN_TERMINAL_IDLE_TIMEOUT', 900),

    // Hard cap on a session's lifetime; used as the terminal.open command timeout (Fleet allows at most 3600s).
    'max_duration' => (int) env('KILN_TERMINAL_MAX_DURATION', 3600),

    // Unix user the shell runs as unless another is chosen.
    'default_user' => env('KILN_TERMINAL_DEFAULT_USER', 'root'),

    'term' => 'xterm-256color',
    'shell' => '/bin/bash',

    // Max base64 characters per broadcast message (Reverb's default max message size is 10 KB).
    'broadcast_chunk_bytes' => 6000,

    // Max decoded bytes accepted per input request.
    'input_max_bytes' => 16384,

    // Frames returned per catch-up request.
    'frames_page_size' => 500,
];
