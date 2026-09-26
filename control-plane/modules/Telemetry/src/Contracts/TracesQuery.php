<?php

namespace Kiln\Telemetry\Contracts;

use DateTimeInterface;
use Kiln\Telemetry\Contracts\Data\Trace;
use Kiln\Telemetry\Contracts\Data\TraceSummary;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;

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
