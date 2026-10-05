<?php

namespace Kiln\Apm\Otlp;

use Kiln\Apm\Span;
use Stringable;
use Throwable;

/**
 * Encodes spans and log records as OTLP/HTTP JSON (opentelemetry-proto JSON mapping:
 * hex trace/span ids, int64 as strings, enums as integers).
 */
final class Encoder
{
    public const SCOPE_NAME = 'kiln/apm-laravel';

    public const VERSION = '0.1.0';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private const MAX_STRING = 16384;

    /** @param array<string, mixed> $resource */
    public function __construct(private array $resource)
    {
    }

    /** @param list<Span> $spans */
    public function traces(array $spans): string
    {
        $encoded = [];

        foreach ($spans as $span) {
            $encoded[] = $this->span($span);
        }

        return json_encode([
            'resourceSpans' => [[
                'resource' => ['attributes' => self::attributes($this->resource)],
                'scopeSpans' => [[
                    'scope' => ['name' => self::SCOPE_NAME, 'version' => self::VERSION],
                    'spans' => $encoded,
                ]],
            ]],
        ], self::JSON_FLAGS) ?: '{}';
    }

    /**
     * @param list<array{timeNs: int, level: string, message: string, context: array<string, mixed>, traceId: ?string, spanId: ?string}> $logs
     */
    public function logs(array $logs): string
    {
        $records = [];

        foreach ($logs as $log) {
            $record = [
                'timeUnixNano' => (string) $log['timeNs'],
                'observedTimeUnixNano' => (string) $log['timeNs'],
                'severityNumber' => self::severityNumber($log['level']),
                'severityText' => strtoupper($log['level']),
                'body' => ['stringValue' => self::truncate($log['message'])],
                'attributes' => self::attributes($log['context']),
            ];

            if ($log['traceId'] !== null && $log['spanId'] !== null) {
                $record['traceId'] = $log['traceId'];
                $record['spanId'] = $log['spanId'];
                $record['flags'] = 1;
            }

            $records[] = $record;
        }

        return json_encode([
            'resourceLogs' => [[
                'resource' => ['attributes' => self::attributes($this->resource)],
                'scopeLogs' => [[
                    'scope' => ['name' => self::SCOPE_NAME, 'version' => self::VERSION],
                    'logRecords' => $records,
                ]],
            ]],
        ], self::JSON_FLAGS) ?: '{}';
    }

    /** @return array<string, mixed> */
    private function span(Span $span): array
    {
        $out = [
            'traceId' => $span->traceId,
            'spanId' => $span->spanId,
            'name' => $span->name,
            'kind' => $span->kind,
            'startTimeUnixNano' => (string) $span->startNs,
            'endTimeUnixNano' => (string) max($span->endNs, $span->startNs),
            'attributes' => self::attributes($span->attributes),
            'status' => ['code' => $span->status],
        ];

        if ($span->parentSpanId !== null) {
            $out['parentSpanId'] = $span->parentSpanId;
        }

        if ($span->statusMessage !== null && $span->status === Span::STATUS_ERROR) {
            $out['status']['message'] = self::truncate($span->statusMessage);
        }

        $events = [];

        foreach ($span->events as $event) {
            $events[] = [
                'timeUnixNano' => (string) $event['timeNs'],
                'name' => $event['name'],
                'attributes' => self::attributes($event['attributes']),
            ];
        }

        foreach ($span->exceptions as [$e, $timeNs, $handled]) {
            $events[] = [
                'timeUnixNano' => (string) $timeNs,
                'name' => 'exception',
                'attributes' => self::attributes(self::exceptionAttributes($e, $handled)),
            ];
        }

        if ($events !== []) {
            $out['events'] = $events;
        }

        if ($span->links !== []) {
            $out['links'] = array_map(fn (array $link) => [
                'traceId' => $link['traceId'],
                'spanId' => $link['spanId'],
                'attributes' => self::attributes($link['attributes'] ?? []),
            ], $span->links);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function exceptionAttributes(Throwable $e, bool $handled): array
    {
        return [
            'exception.type' => get_class($e),
            'exception.message' => $e->getMessage(),
            'exception.stacktrace' => get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine()."\nStack trace:\n".$e->getTraceAsString(),
            'exception.escaped' => ! $handled,
            'kiln.exception.handled' => $handled,
            'code.filepath' => $e->getFile(),
            'code.lineno' => $e->getLine(),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    public static function attributes(array $attributes): array
    {
        $out = [];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $out[] = ['key' => (string) $key, 'value' => self::value($value)];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function value(mixed $value, int $depth = 0): array
    {
        return match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => is_finite($value) ? ['doubleValue' => $value] : ['stringValue' => (string) $value],
            is_string($value) => ['stringValue' => self::truncate($value)],
            is_array($value) && $depth < 4 => array_is_list($value)
                ? ['arrayValue' => ['values' => array_map(fn ($v) => self::value($v, $depth + 1), $value)]]
                : ['kvlistValue' => ['values' => array_map(
                    fn ($k, $v) => ['key' => (string) $k, 'value' => self::value($v, $depth + 1)],
                    array_keys($value),
                    array_values($value),
                )]],
            $value instanceof Throwable => ['stringValue' => get_class($value).': '.self::truncate($value->getMessage())],
            $value instanceof Stringable => ['stringValue' => self::truncate((string) $value)],
            $value === null => ['stringValue' => ''],
            is_object($value) => ['stringValue' => get_class($value)],
            default => ['stringValue' => self::truncate((string) json_encode($value, self::JSON_FLAGS))],
        };
    }

    public static function severityNumber(string $level): int
    {
        return match (strtolower($level)) {
            'debug' => 5,
            'info' => 9,
            'notice' => 10,
            'warning' => 13,
            'error' => 17,
            'critical' => 18,
            'alert' => 19,
            'emergency' => 21,
            default => 0,
        };
    }

    private static function truncate(string $value): string
    {
        return strlen($value) > self::MAX_STRING ? substr($value, 0, self::MAX_STRING).'…' : $value;
    }
}
