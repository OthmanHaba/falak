<?php

namespace Kiln\Telemetry\Contracts;

use DateTimeInterface;
use Kiln\Telemetry\Contracts\Data\LogLine;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;

/**
 * Loki client (query_range). Callers must include an organization matcher
 * (`kiln_org_id="…"`) in the stream selector — build matchers with {@see PromQl::label()}.
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
