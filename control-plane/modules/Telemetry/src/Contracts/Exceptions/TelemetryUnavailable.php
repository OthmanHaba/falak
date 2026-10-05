<?php

namespace Falak\Telemetry\Contracts\Exceptions;

use RuntimeException;

/** The backend is not configured or could not be reached. */
final class TelemetryUnavailable extends RuntimeException
{
    public static function notConfigured(string $component): self
    {
        return new self("{$component} is not configured.");
    }

    public static function unreachable(string $component, string $reason): self
    {
        return new self("{$component} is unreachable: {$reason}");
    }
}
