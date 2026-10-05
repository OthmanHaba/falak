<?php

namespace Falak\Telemetry\Contracts;

use DateTimeInterface;
use Falak\Telemetry\Contracts\Data\Trace;
use Falak\Telemetry\Contracts\Data\TraceSummary;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;

/**
 * Tempo client: trace by id and TraceQL search.
 */
interface TracesQuery
{
    /**
     * @return Trace|null null when Tempo does not know the trace
     *
     * @throws TelemetryUnavailable
     * @throws TelemetryQueryFailed
     */
    public function trace(string $traceId, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): ?Trace;

    /**
     * @return list<TraceSummary>
     *
     * @throws TelemetryUnavailable
     * @throws TelemetryQueryFailed
     */
    public function search(string $traceql, DateTimeInterface $start, DateTimeInterface $end, int $limit = 20): array;
}
