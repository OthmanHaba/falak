<?php

// Shared fixtures for Providers tests (loaded with require_once; not a test file).

if (! defined('PROVIDERS_TEST_KEY')) {
    define('PROVIDERS_TEST_KEY', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhfLAQI6GIEE8z6YcLPiiVBw4Tu9QD2miHt6Q8acbUV dev@kiln');
}

/** Retry immediately in tests. */
const PROVIDERS_TEST_HTTP = ['retries' => 3, 'retry_sleep_ms' => 0];
