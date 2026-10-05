<?php

namespace Falak\Telemetry\Contracts;

use DateTimeInterface;
use Falak\Telemetry\Contracts\Data\LogLine;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;

/**
 * Loki client (query_range). Callers must include an organization matcher
 * (`falak_org_id="…"`) in the stream selector — build matchers with {@see PromQl::label()}.
 */
interface LogsQuery
{
    /**
     * @param  'backward'|'forward'  $direction
     * @return list<LogLine> newest first for "backward"
     *
     * @throws TelemetryUnavailable
     * @throws TelemetryQueryFailed
     */
    public function queryRange(string $logql, DateTimeInterface $start, DateTimeInterface $end, int $limit = 200, string $direction = 'backward'): array;
}
