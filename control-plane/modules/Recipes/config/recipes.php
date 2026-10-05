<?php

return [
    // system.exec timeout for recipe runs (seconds); a run may lower or raise it up to max_timeout.
    'timeout' => (int) env('FALAK_RECIPES_TIMEOUT', 900),
    'max_timeout' => 3600,

    // Maximum servers per run.
    'max_servers' => 100,

    // Maximum script size (bytes).
    'max_script_bytes' => 65536,
];
