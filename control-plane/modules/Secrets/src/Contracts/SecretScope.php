<?php

namespace Falak\Secrets\Contracts;

/**
 * Where a secret is defined. A service sees the secrets of its own scope chain; the nearest scope wins.
 */
enum SecretScope: string
{
    case Organization = 'organization';
    case Project = 'project';
    case Environment = 'environment';
    case Service = 'service';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Lower = nearer to the service (wins over farther scopes). */
    public function distance(): int
    {
        return match ($this) {
            self::Service => 0,
            self::Environment => 1,
            self::Project => 2,
            self::Organization => 3,
        };
    }
}
