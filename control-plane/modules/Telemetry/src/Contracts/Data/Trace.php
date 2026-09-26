<?php

namespace Kiln\Telemetry\Contracts\Data;

final readonly class Trace
{
    /**
     * @param  list<Span>  $spans  ordered by start time
     */
    public function __construct(
        public string $traceId,
        public array $spans,
    ) {}

    /** Organization id from the spans' `kiln.org.id` resource attribute (null if absent). */
    public function organizationId(): ?string
    {
        foreach ($this->spans as $span) {
            $org = $span->resource['kiln.org.id'] ?? null;

            if (is_string($org) && $org !== '') {
                return $org;
            }
        }

        return null;
    }

    public function root(): ?Span
    {
        foreach ($this->spans as $span) {
            if ($span->parentSpanId === null) {
                return $span;
            }
        }

        return $this->spans[0] ?? null;
    }

    /**
     * @return array{trace_id: string, spans: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['trace_id' => $this->traceId, 'spans' => array_map(fn (Span $s) => $s->toArray(), $this->spans)];
    }
}
