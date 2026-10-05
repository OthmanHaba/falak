<?php

namespace Falak\Telemetry\Infrastructure\Metrics;

use DateTimeInterface;
use Falak\Telemetry\Contracts\Data\MetricSeries;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\MetricsBackend;
use Falak\Telemetry\Infrastructure\HttpClient;
use Illuminate\Http\Client\PendingRequest;

/**
 * Prometheus HTTP API (/api/v1/query, /api/v1/query_range), spoken by both VictoriaMetrics and Mimir.
 */
abstract class PrometheusHttpBackend implements MetricsBackend
{
    public function __construct(protected readonly HttpClient $http) {}

    public function query(string $promql, ?DateTimeInterface $at = null): array
    {
        return $this->call('/api/v1/query', array_filter([
            'query' => $promql,
            'time' => $at?->getTimestamp(),
        ], fn ($v) => $v !== null));
    }

    public function queryRange(string $promql, DateTimeInterface $start, DateTimeInterface $end, int $stepSeconds): array
    {
        return $this->call('/api/v1/query_range', [
            'query' => $promql,
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
            'step' => max(1, $stepSeconds),
        ]);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return list<MetricSeries>
     */
    private function call(string $path, array $params): array
    {
        // POST form keeps long queries out of URLs/access logs; both backends accept it.
        $response = $this->http->ensureSuccessful(
            $this->http->send(fn (PendingRequest $r) => $r->asForm()->post($path, $params)),
        );

        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? null) !== 'success') {
            throw new TelemetryQueryFailed($this->http->component, is_array($body) ? (string) ($body['error'] ?? 'unexpected response') : 'invalid JSON');
        }

        return self::parse((array) ($body['data'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<MetricSeries>
     */
    public static function parse(array $data): array
    {
        $type = $data['resultType'] ?? null;
        $result = $data['result'] ?? [];

        if ($type === 'scalar' || $type === 'string') {
            return [new MetricSeries([], [self::point($result)])];
        }

        $series = [];

        foreach ((array) $result as $row) {
            $labels = array_map('strval', (array) ($row['metric'] ?? []));
            $points = $type === 'matrix'
                ? array_map(self::point(...), (array) ($row['values'] ?? []))
                : [self::point($row['value'] ?? [0, null])];

            $series[] = new MetricSeries($labels, array_values($points));
        }

        return $series;
    }

    /**
     * @return array{0: int, 1: float|null}
     */
    private static function point(mixed $pair): array
    {
        $pair = array_values((array) $pair);
        $value = $pair[1] ?? null;
        $number = is_numeric($value) ? (float) $value : null;

        if ($number !== null && (is_nan($number) || is_infinite($number))) {
            $number = null;
        }

        return [(int) floor((float) ($pair[0] ?? 0)), $number];
    }
}
