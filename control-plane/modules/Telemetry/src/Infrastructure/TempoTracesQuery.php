<?php

namespace Kiln\Telemetry\Infrastructure;

use DateTimeInterface;
use Kiln\Telemetry\Contracts\Data\Span;
use Kiln\Telemetry\Contracts\Data\Trace;
use Kiln\Telemetry\Contracts\Data\TraceSummary;
use Kiln\Telemetry\Contracts\TracesQuery;

/**
 * Tempo HTTP API: trace by id (/api/v2/traces, falling back to /api/traces) and TraceQL search.
 */
final class TempoTracesQuery implements TracesQuery
{
    public function __construct(private readonly HttpClient $http) {}

    public static function fromConfig(): self
    {
        return new self(new HttpClient('Tempo', config('telemetry.tempo.url')));
    }

    public function trace(string $traceId, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): ?Trace
    {
        $traceId = strtolower($traceId);
        $query = array_filter(['start' => $start?->getTimestamp(), 'end' => $end?->getTimestamp()], fn ($v) => $v !== null);

        $response = $this->http->get("/api/v2/traces/{$traceId}", $query);

        // Older Tempo versions have no v2 endpoint.
        if (in_array($response->status(), [404, 405], true) && ! $this->looksLikeTraceNotFound($response->body())) {
            $response = $this->http->get("/api/traces/{$traceId}", $query);
        }

        if ($response->status() === 404) {
            return null;
        }

        $body = $this->http->ensureSuccessful($response)->json();

        if (! is_array($body)) {
            return null;
        }

        $resourceSpans = $body['trace']['resourceSpans'] ?? $body['resourceSpans'] ?? $body['batches'] ?? [];
        $spans = self::parseResourceSpans((array) $resourceSpans);

        return $spans === [] ? null : new Trace($traceId, $spans);
    }

    public function search(string $traceql, DateTimeInterface $start, DateTimeInterface $end, int $limit = 20): array
    {
        $body = $this->http->ensureSuccessful($this->http->get('/api/search', [
            'q' => $traceql,
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
            'limit' => max(1, min(500, $limit)),
        ]))->json();

        $summaries = [];

        foreach ((array) ($body['traces'] ?? []) as $trace) {
            $spanSets = $trace['spanSets'] ?? (isset($trace['spanSet']) ? [$trace['spanSet']] : []);
            $matched = 0;

            foreach ((array) $spanSets as $set) {
                $matched += (int) ($set['matched'] ?? count((array) ($set['spans'] ?? [])));
            }

            $summaries[] = new TraceSummary(
                traceId: str_pad(strtolower((string) ($trace['traceID'] ?? '')), 32, '0', STR_PAD_LEFT),
                rootService: isset($trace['rootServiceName']) ? (string) $trace['rootServiceName'] : null,
                rootName: isset($trace['rootTraceName']) ? (string) $trace['rootTraceName'] : null,
                startUnixNano: (string) ($trace['startTimeUnixNano'] ?? '0'),
                durationMs: (float) ($trace['durationMs'] ?? 0),
                matchedSpans: $matched,
            );
        }

        return $summaries;
    }

    /**
     * @param  list<array<string, mixed>>|array<int, mixed>  $resourceSpans
     * @return list<Span>
     */
    public static function parseResourceSpans(array $resourceSpans): array
    {
        $spans = [];

        foreach ($resourceSpans as $batch) {
            $resource = self::attributes((array) ($batch['resource']['attributes'] ?? []));
            $service = (string) ($resource['service.name'] ?? 'unknown');
            $scopes = $batch['scopeSpans'] ?? $batch['instrumentationLibrarySpans'] ?? $batch['ils'] ?? [];

            foreach ((array) $scopes as $scope) {
                foreach ((array) ($scope['spans'] ?? []) as $span) {
                    $parent = self::id($span['parentSpanId'] ?? null);

                    $spans[] = new Span(
                        traceId: (string) self::id($span['traceId'] ?? null),
                        spanId: (string) self::id($span['spanId'] ?? null),
                        parentSpanId: $parent !== '' ? $parent : null,
                        name: (string) ($span['name'] ?? ''),
                        service: $service,
                        kind: self::kind($span['kind'] ?? null),
                        startUnixNano: (string) ($span['startTimeUnixNano'] ?? '0'),
                        endUnixNano: (string) ($span['endTimeUnixNano'] ?? $span['startTimeUnixNano'] ?? '0'),
                        status: self::status($span['status']['code'] ?? null),
                        statusMessage: isset($span['status']['message']) && $span['status']['message'] !== '' ? (string) $span['status']['message'] : null,
                        attributes: self::attributes((array) ($span['attributes'] ?? [])),
                        resource: $resource,
                        events: array_map(fn (array $event) => [
                            'name' => (string) ($event['name'] ?? ''),
                            'time_unix_nano' => (string) ($event['timeUnixNano'] ?? '0'),
                            'attributes' => self::attributes((array) ($event['attributes'] ?? [])),
                        ], array_values(array_filter((array) ($span['events'] ?? []), 'is_array'))),
                    );
                }
            }
        }

        usort($spans, fn (Span $a, Span $b) => strlen($a->startUnixNano) === strlen($b->startUnixNano)
            ? strcmp($a->startUnixNano, $b->startUnixNano)
            : strlen($a->startUnixNano) <=> strlen($b->startUnixNano));

        return $spans;
    }

    /**
     * OTLP KeyValue list → flat map.
     *
     * @param  array<int, mixed>  $list
     * @return array<string, scalar|null>
     */
    public static function attributes(array $list): array
    {
        $out = [];

        foreach ($list as $kv) {
            if (is_array($kv) && isset($kv['key'])) {
                $out[(string) $kv['key']] = self::value((array) ($kv['value'] ?? []));
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function value(array $value): string|int|float|bool|null
    {
        return match (true) {
            array_key_exists('stringValue', $value) => (string) $value['stringValue'],
            array_key_exists('intValue', $value) => (int) $value['intValue'],
            array_key_exists('doubleValue', $value) => (float) $value['doubleValue'],
            array_key_exists('boolValue', $value) => (bool) $value['boolValue'],
            array_key_exists('arrayValue', $value) => json_encode(array_map(
                fn ($v) => self::value((array) $v),
                (array) ($value['arrayValue']['values'] ?? []),
            ), JSON_UNESCAPED_SLASHES) ?: null,
            array_key_exists('kvlistValue', $value) => json_encode(self::attributes((array) ($value['kvlistValue']['values'] ?? [])), JSON_UNESCAPED_SLASHES) ?: null,
            array_key_exists('bytesValue', $value) => (string) $value['bytesValue'],
            default => null,
        };
    }

    /** Hex ids pass through; Tempo's protobuf-JSON ids are base64. */
    public static function id(mixed $id): string
    {
        if (! is_string($id) || $id === '') {
            return '';
        }

        if (preg_match('/^[0-9a-fA-F]+$/', $id) === 1 && in_array(strlen($id), [16, 32], true)) {
            return strtolower($id);
        }

        $binary = base64_decode($id, true);

        return $binary === false ? strtolower($id) : bin2hex($binary);
    }

    private static function kind(mixed $kind): string
    {
        $map = [1 => 'internal', 2 => 'server', 3 => 'client', 4 => 'producer', 5 => 'consumer'];

        if (is_int($kind) || ctype_digit((string) $kind)) {
            return $map[(int) $kind] ?? 'unspecified';
        }

        $name = strtolower(str_replace('SPAN_KIND_', '', (string) $kind));

        return in_array($name, $map, true) ? $name : 'unspecified';
    }

    private static function status(mixed $code): string
    {
        return match ((string) $code) {
            '2', 'STATUS_CODE_ERROR' => 'error',
            '1', 'STATUS_CODE_OK' => 'ok',
            default => 'unset',
        };
    }

    private function looksLikeTraceNotFound(string $body): bool
    {
        return stripos($body, 'trace not found') !== false;
    }
}
