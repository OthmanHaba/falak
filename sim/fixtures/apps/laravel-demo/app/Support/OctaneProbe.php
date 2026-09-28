<?php

namespace App\Support;

/**
 * Kiln E2E: per-worker request counter. A class static lives as long as the PHP worker (Octane keeps it across
 * requests); under classic FrankenPHP / PHP-FPM every request starts at zero. (A `static` inside a route closure
 * would not work: `artisan optimize` caches routes, and cached closures are unserialized on every request.)
 */
final class OctaneProbe
{
    public static int $served = 0;
}
