<?php

namespace Falak\Telemetry\Contracts\Data;

/**
 * How many requests the edge served for a site in a time range, and how many of them answered 5xx.
 */
final readonly class RequestCounts
{
    public function __construct(
        public int $total,
        public int $errors,
    ) {}

    /** Share of 5xx responses (0–1); 0 without requests. */
    public function errorRate(): float
    {
        return $this->total > 0 ? $this->errors / $this->total : 0.0;
    }

    /**
     * @return array{total: int, errors: int, rate: float}
     */
    public function toArray(): array
    {
        return ['total' => $this->total, 'errors' => $this->errors, 'rate' => round($this->errorRate(), 4)];
    }
}
