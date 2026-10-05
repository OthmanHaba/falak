<?php

namespace Falak\Telemetry\Contracts\Exceptions;

use RuntimeException;

/** The backend rejected the query (bad syntax, limits, 4xx/5xx with an error body). */
final class TelemetryQueryFailed extends RuntimeException
{
    public function __construct(public readonly string $component, string $message, public readonly ?int $status = null)
    {
        parent::__construct("{$component} query failed: {$message}");
    }
}
