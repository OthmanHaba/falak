<?php

namespace Kiln\Telemetry\Contracts\Data;

final readonly class Span
{
    /**
     * @param  string  $status  "ok" | "error" | "unset"
     * @param  array<string, scalar|null>  $attributes  span attributes
     * @param  array<string, scalar|null>  $resource  resource attributes
     * @param  list<array{name: string, time_unix_nano: string, attributes: array<string, scalar|null>}>  $events
     */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
        public string $name,
        public string $service,
        public string $kind,
        public string $startUnixNano,
        public string $endUnixNano,
        public string $status,
        public ?string $statusMessage,
        public array $attributes,
        public array $resource,
        public array $events = [],
    ) {}

    public function durationMs(): float
    {
        return max(0, ((int) $this->endUnixNano - (int) $this->startUnixNano) / 1_000_000);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'name' => $this->name,
            'service' => $this->service,
            'kind' => $this->kind,
            'start_unix_nano' => $this->startUnixNano,
            'end_unix_nano' => $this->endUnixNano,
            'duration_ms' => round($this->durationMs(), 3),
            'status' => $this->status,
            'status_message' => $this->statusMessage,
            'attributes' => (object) $this->attributes,
            'resource' => (object) $this->resource,
            'events' => array_map(fn (array $e) => [...$e, 'attributes' => (object) $e['attributes']], $this->events),
        ];
    }
}
