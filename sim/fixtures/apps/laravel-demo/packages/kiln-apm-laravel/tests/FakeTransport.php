<?php

namespace Kiln\Apm\Tests;

use Kiln\Apm\Transport\Transport;

final class FakeTransport implements Transport
{
    /** @var list<array{path: string, body: string}> */
    public array $sent = [];

    public function send(string $path, string $body): bool
    {
        $this->sent[] = ['path' => $path, 'body' => $body];

        return true;
    }

    /** @return list<array<string, mixed>> decoded payloads for a signal path */
    public function payloads(string $path): array
    {
        return array_values(array_map(
            fn ($s) => json_decode($s['body'], true, flags: JSON_THROW_ON_ERROR),
            array_filter($this->sent, fn ($s) => $s['path'] === $path),
        ));
    }

    /**
     * Flattened spans with attributes decoded to a plain key => value map.
     *
     * @return list<array<string, mixed>>
     */
    public function spans(): array
    {
        $spans = [];

        foreach ($this->payloads('/v1/traces') as $payload) {
            foreach ($payload['resourceSpans'] as $rs) {
                foreach ($rs['scopeSpans'] as $ss) {
                    foreach ($ss['spans'] as $span) {
                        $span['attrs'] = self::attrs($span['attributes']);
                        $span['events'] = array_map(fn ($e) => $e + ['attrs' => self::attrs($e['attributes'])], $span['events'] ?? []);
                        $spans[] = $span;
                    }
                }
            }
        }

        return $spans;
    }

    /** @return list<array<string, mixed>> */
    public function spansOfType(string $type): array
    {
        return array_values(array_filter($this->spans(), fn ($s) => ($s['attrs']['kiln.event.type'] ?? null) === $type));
    }

    /** @return list<array<string, mixed>> */
    public function logRecords(): array
    {
        $records = [];

        foreach ($this->payloads('/v1/logs') as $payload) {
            foreach ($payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'] as $record) {
                $record['attrs'] = self::attrs($record['attributes']);
                $records[] = $record;
            }
        }

        return $records;
    }

    /** @param list<array{key: string, value: array<string, mixed>}> $attributes */
    public static function attrs(array $attributes): array
    {
        $out = [];

        foreach ($attributes as $attribute) {
            $value = $attribute['value'];
            $out[$attribute['key']] = match (true) {
                array_key_exists('stringValue', $value) => $value['stringValue'],
                array_key_exists('intValue', $value) => (int) $value['intValue'],
                array_key_exists('doubleValue', $value) => (float) $value['doubleValue'],
                array_key_exists('boolValue', $value) => $value['boolValue'],
                default => $value,
            };
        }

        return $out;
    }
}
