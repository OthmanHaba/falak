<?php

namespace Kiln\Telemetry\Contracts\Data;

final readonly class TraceSummary
{
    public function __construct(
        public string $traceId,
        public ?string $rootService,
        public ?string $rootName,
        public string $startUnixNano,
        public float $durationMs,
        public int $matchedSpans = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'root_service' => $this->rootService,
            'root_name' => $this->rootName,
            'start_unix_nano' => $this->startUnixNano,
            'duration_ms' => $this->durationMs,
            'matched_spans' => $this->matchedSpans,
        ];
    }
}
