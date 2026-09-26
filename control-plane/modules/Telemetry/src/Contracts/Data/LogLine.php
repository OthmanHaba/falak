<?php

namespace Kiln\Telemetry\Contracts\Data;

use DateTimeImmutable;

final readonly class LogLine
{
    /**
     * @param  string  $timestampNs  nanoseconds since epoch (string: exceeds float precision)
     * @param  array<string, string>  $labels  stream labels
     * @param  array<string, string>  $metadata  structured metadata (trace_id, span_id, severity_text…)
     */
    public function __construct(
        public string $timestampNs,
        public string $line,
        public array $labels,
        public array $metadata = [],
    ) {}

    public function at(): DateTimeImmutable
    {
        $seconds = intdiv((int) $this->timestampNs, 1_000_000_000);
        $micros = intdiv(((int) $this->timestampNs) % 1_000_000_000, 1000);

        return (new DateTimeImmutable('@'.$seconds))->modify("+{$micros} microseconds");
    }

    public function traceId(): ?string
    {
        return $this->metadata['trace_id'] ?? $this->labels['trace_id'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ts' => $this->timestampNs,
            'at' => $this->at()->format('Y-m-d\TH:i:s.uP'),
            'line' => $this->line,
            'labels' => (object) $this->labels,
            'metadata' => (object) $this->metadata,
            'trace_id' => $this->traceId(),
        ];
    }
}
