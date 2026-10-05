<?php

namespace Falak\Telemetry\Contracts;

use DateTimeInterface;
use Falak\Telemetry\Contracts\Data\MetricSeries;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;

/**
 * PromQL over HTTP against the configured metrics backend (VictoriaMetrics or Mimir).
 *
 * Callers are responsible for scoping queries to resources they authorized (e.g. a
 * `falak_server_id="…"` matcher built with {@see PromQl::label()}).
 */
interface MetricsBackend
{
    /** Adapter name: "victoriametrics" | "mimir". */
    public function name(): string;

    /**
     * Instant query (/api/v1/query). Each series holds exactly one point.
     *
     * @return list<MetricSeries>
     *
     * @throws TelemetryUnavailable when no query URL is configured or the backend is unreachable
     * @throws TelemetryQueryFailed when the backend rejects the query
     */
    public function query(string $promql, ?DateTimeInterface $at = null): array;

    /**
     * Range query (/api/v1/query_range).
     *
     * @return list<MetricSeries>
     *
     * @throws TelemetryUnavailable
     * @throws TelemetryQueryFailed
     */
    public function queryRange(string $promql, DateTimeInterface $start, DateTimeInterface $end, int $stepSeconds): array;
}
