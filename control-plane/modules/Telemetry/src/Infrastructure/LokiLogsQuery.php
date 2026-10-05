<?php

namespace Falak\Telemetry\Infrastructure;

use DateTimeInterface;
use Falak\Telemetry\Contracts\Data\LogLine;
use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\LogsQuery;

/**
 * Loki query_range client. Structured metadata (OTLP attributes such as trace_id) arrives as the
 * optional third element of each value tuple (Loki 3, `X-Loki-Response-Encoding-Flags: categorize-labels`
 * is not required: Loki returns it inline for OTLP-ingested streams).
 */
final class LokiLogsQuery implements LogsQuery
{
    public function __construct(private readonly HttpClient $http) {}

    public static function fromConfig(): self
    {
        $tenant = config('telemetry.loki.tenant');

        return new self(new HttpClient('Loki', config('telemetry.loki.url'), $tenant ? ['X-Scope-OrgID' => (string) $tenant] : []));
    }

    public function queryRange(string $logql, DateTimeInterface $start, DateTimeInterface $end, int $limit = 200, string $direction = 'backward'): array
    {
        $direction = $direction === 'forward' ? 'forward' : 'backward';
        $limit = max(1, min(5000, $limit));

        $response = $this->http->ensureSuccessful($this->http->get('/loki/api/v1/query_range', [
            'query' => $logql,
            'start' => self::nanos($start),
            'end' => self::nanos($end),
            'limit' => $limit,
            'direction' => $direction,
        ]));

        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? null) !== 'success') {
            throw new TelemetryQueryFailed('Loki', is_array($body) ? (string) ($body['error'] ?? 'unexpected response') : 'invalid JSON');
        }

        if (($body['data']['resultType'] ?? 'streams') !== 'streams') {
            throw new TelemetryQueryFailed('Loki', 'Only log (stream) queries are supported.');
        }

        $lines = [];

        foreach ((array) ($body['data']['result'] ?? []) as $stream) {
            $labels = array_map('strval', (array) ($stream['stream'] ?? []));

            foreach ((array) ($stream['values'] ?? []) as $value) {
                $metadata = isset($value[2]) && is_array($value[2]) ? self::flattenMetadata($value[2]) : [];
                $lines[] = new LogLine((string) $value[0], (string) ($value[1] ?? ''), $labels, $metadata);
            }
        }

        usort($lines, fn (LogLine $a, LogLine $b) => $direction === 'forward'
            ? self::compareNs($a->timestampNs, $b->timestampNs)
            : self::compareNs($b->timestampNs, $a->timestampNs));

        return array_slice($lines, 0, $limit);
    }

    public static function nanos(DateTimeInterface $at): string
    {
        return $at->format('U').str_pad($at->format('u'), 6, '0', STR_PAD_LEFT).'000';
    }

    private static function compareNs(string $a, string $b): int
    {
        return strlen($a) === strlen($b) ? strcmp($a, $b) : strlen($a) <=> strlen($b);
    }

    /**
     * Loki returns either {"key": "value"} or, with categorized labels, {"structuredMetadata": {...}, "parsed": {...}}.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, string>
     */
    private static function flattenMetadata(array $meta): array
    {
        $flat = [];

        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_scalar($v)) {
                        $flat[(string) $k] = (string) $v;
                    }
                }
            } elseif (is_scalar($value)) {
                $flat[(string) $key] = (string) $value;
            }
        }

        return $flat;
    }
}
