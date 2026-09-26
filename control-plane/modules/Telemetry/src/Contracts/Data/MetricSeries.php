<?php

namespace Kiln\Telemetry\Contracts\Data;

final readonly class MetricSeries
{
    /**
     * @param  array<string, string>  $labels
     * @param  list<array{0: int, 1: float|null}>  $points  [unix seconds, value]; NaN/Inf become null
     */
    public function __construct(
        public array $labels,
        public array $points,
    ) {}

    public function last(): ?float
    {
        return $this->points === [] ? null : $this->points[array_key_last($this->points)][1];
    }

    /**
     * @return array{labels: array<string, string>, points: list<array{0: int, 1: float|null}>}
     */
    public function toArray(): array
    {
        return ['labels' => $this->labels, 'points' => $this->points];
    }
}
