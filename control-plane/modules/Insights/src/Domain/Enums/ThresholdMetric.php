<?php

namespace Falak\Insights\Domain\Enums;

enum ThresholdMetric: string
{
    case P95 = 'p95';
    case Max = 'max';

    public function label(): string
    {
        return match ($this) {
            self::P95 => 'p95',
            self::Max => 'max',
        };
    }
}
