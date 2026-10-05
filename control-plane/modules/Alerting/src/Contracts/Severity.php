<?php

namespace Falak\Alerting\Contracts;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 10,
            self::Warning => 20,
            self::Critical => 30,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
