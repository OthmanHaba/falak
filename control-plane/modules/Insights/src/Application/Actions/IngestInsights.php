<?php

namespace Falak\Insights\Application\Actions;

use Carbon\CarbonImmutable;
use Falak\Insights\Application\HeartbeatTracker;
use Falak\Insights\Application\IssueTracker;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Domain\Support\Fingerprinter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ingests one batch of the agent's insights tee (contracts/telemetry README):
 * `exception` lines → occurrences grouped into issues, `aggregate` lines → per-minute rows,
 * `cron_heartbeat` lines → heartbeat monitors. Idempotent for re-sent batches.
 */
final class IngestInsights
{
    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public function __construct(
        private readonly IssueTracker $issues,
        private readonly HeartbeatTracker $heartbeats,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{exceptions: int, aggregates: int, heartbeats: int, skipped: int}
     */
    public function __invoke(string $organizationId, ?string $serverId, string $agentId, array $items): array
    {
        $exceptions = [];
        $aggregates = [];
        $heartbeats = [];
        $skipped = 0;

        foreach ($items as $item) {
            match ($item['kind'] ?? null) {
                'exception' => $exceptions[] = $item,
                'aggregate' => $aggregates[] = $item,
                'cron_heartbeat' => $heartbeats[] = $item,
                default => $skipped++,
            };
        }

        $sourceId = $serverId ?? $agentId;
        $sites = [];
        $result = ['exceptions' => 0, 'aggregates' => 0, 'heartbeats' => 0, 'skipped' => $skipped];

        $result['exceptions'] = $this->exceptions($organizationId, $serverId, $exceptions, $sites, $result['skipped']);
        $result['aggregates'] = $this->aggregates($organizationId, $sourceId, $aggregates, $sites, $result['skipped']);

        foreach ($heartbeats as $heartbeat) {
            $siteId = $this->siteId($heartbeat);

            if ($siteId !== null) {
                $sites[$siteId] = true;
            }

            try {
                $this->heartbeats->record($organizationId, $serverId, $sourceId, $siteId, $heartbeat);
                $result['heartbeats']++;
            } catch (Throwable $e) {
                $result['skipped']++;
                Log::warning('insights: invalid cron heartbeat', ['organization_id' => $organizationId, 'error' => $e->getMessage()]);
            }
        }

        $this->touchSites($organizationId, array_keys($sites));

        if ($result['skipped'] > 0) {
            Log::info('insights: skipped invalid lines', ['organization_id' => $organizationId, 'agent_id' => $agentId, 'skipped' => $result['skipped']]);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, true>  $sites
     */
    private function exceptions(string $organizationId, ?string $serverId, array $items, array &$sites, int &$skipped): int
    {
        $fingerprinter = new Fingerprinter((int) config('insights.fingerprint.frames', 3));
        $limits = (array) config('insights.limits');
        $groups = [];

        foreach ($items as $item) {
            $siteId = $this->siteId($item);
            $type = is_string($item['type'] ?? null) ? $item['type'] : null;

            if ($siteId === null || $type === null || trim($type) === '') {
                $skipped++;

                continue;
            }

            $message = $this->truncate((string) ($item['message'] ?? ''), (int) ($limits['message_bytes'] ?? 4096));
            $stacktrace = is_string($item['stacktrace'] ?? null) && $item['stacktrace'] !== '' ? $item['stacktrace'] : null;
            $fingerprint = $fingerprinter->fingerprint($type, $message, $stacktrace);
            $at = $this->time($item['at'] ?? null);
            $handled = filter_var($item['handled'] ?? false, FILTER_VALIDATE_BOOL);
            $userId = isset($item['user_id']) && is_scalar($item['user_id']) && (string) $item['user_id'] !== '' ? (string) $item['user_id'] : null;
            $traceId = $this->hexId($item['trace_id'] ?? null, 32);
            $spanId = $this->hexId($item['span_id'] ?? null, 16);
            $route = is_string($item['route_or_name'] ?? null) ? $this->truncate($item['route_or_name'], (int) ($limits['name_bytes'] ?? 1000)) : null;
            $eventType = is_string($item['event_type'] ?? null) ? substr($item['event_type'], 0, 32) : null;
            $issueFingerprint = hash('sha256', $siteId."\n".$fingerprint->hash);

            $groups[$issueFingerprint] ??= ['site_id' => $siteId, 'fingerprint' => $fingerprint, 'rows' => []];
            $groups[$issueFingerprint]['rows'][] = [
                'bucket_date' => $at->toDateString(),
                'occurred_at' => $at,
                'minute' => $at->startOfMinute(),
                'organization_id' => $organizationId,
                'site_id' => $siteId,
                'server_id' => $serverId,
                'dedupe_hash' => sha1(implode('|', [$siteId, $traceId, $spanId, $fingerprint->type, $at->format('Y-m-d H:i:s.u'), mb_substr($message, 0, 200)])),
                'type' => $fingerprint->type,
                'message' => $message,
                'stacktrace' => $stacktrace !== null ? $this->truncate($stacktrace, (int) ($limits['stacktrace_bytes'] ?? 16384)) : null,
                'handled' => $handled,
                'user_hash' => $userId !== null ? hash('sha256', $organizationId.'|'.$siteId.'|'.$userId) : null,
                'event_type' => $eventType,
                'route_or_name' => $route,
                'trace_id' => $traceId,
                'span_id' => $spanId,
            ];
        }

        $stored = 0;

        foreach ($groups as $issueFingerprint => $group) {
            $rows = $this->withoutDuplicates($group['rows']);

            if ($rows === []) {
                continue;
            }

            $sites[$group['site_id']] = true;
            usort($rows, fn (array $a, array $b) => $a['occurred_at'] <=> $b['occurred_at']);
            $latest = $rows[array_key_last($rows)];
            $fingerprint = $group['fingerprint'];

            [$issue] = $this->issues->record(
                organizationId: $organizationId,
                kind: IssueKind::Exception,
                fingerprint: $issueFingerprint,
                attributes: [
                    'site_id' => $group['site_id'],
                    'server_id' => $serverId,
                    'title' => $this->truncate($fingerprint->type.': '.($latest['message'] !== '' ? strtok($latest['message'], "\n") : '(no message)'), 500),
                    'culprit' => $fingerprint->culprit !== null ? $this->truncate($fingerprint->culprit, 500) : ($latest['route_or_name'] !== null ? $this->truncate($latest['route_or_name'], 500) : null),
                    'exception_type' => $this->truncate($fingerprint->type, 255),
                    'event_type' => $latest['event_type'],
                    'last_trace_id' => $latest['trace_id'],
                    'sample' => [
                        'message' => $latest['message'],
                        'stacktrace' => $latest['stacktrace'],
                        'route_or_name' => $latest['route_or_name'],
                        'handled' => $latest['handled'],
                        'trace_id' => $latest['trace_id'],
                        'span_id' => $latest['span_id'],
                        'at' => $latest['occurred_at']->toIso8601String(),
                    ],
                ],
                firstAt: $rows[0]['occurred_at'],
                lastAt: $latest['occurred_at'],
                count: count($rows),
                unhandled: count(array_filter($rows, fn (array $row) => ! $row['handled'])),
                userHashes: array_values(array_filter(array_column($rows, 'user_hash'))),
            );

            foreach (array_chunk($rows, 500) as $chunk) {
                $stored += DB::table('insights_exceptions')->insertOrIgnore(array_map(fn (array $row) => [...$row, 'issue_id' => $issue->id], $chunk));
            }
        }

        return $stored;
    }

    /**
     * Drop rows already stored (agent retries) and duplicates within the batch.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutDuplicates(array $rows): array
    {
        $unique = [];

        foreach ($rows as $row) {
            $unique[$row['dedupe_hash']] = $row;
        }

        $existing = DB::table('insights_exceptions')->whereIn('dedupe_hash', array_keys($unique))->pluck('dedupe_hash')->all();

        return array_values(array_diff_key($unique, array_flip($existing)));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, true>  $sites
     */
    private function aggregates(string $organizationId, string $sourceId, array $items, array &$sites, int &$skipped): int
    {
        $rows = [];
        $nameBytes = (int) config('insights.limits.name_bytes', 1000);

        foreach ($items as $item) {
            $siteId = $this->siteId($item);
            $eventType = is_string($item['event_type'] ?? null) ? $item['event_type'] : null;
            $name = is_scalar($item['name'] ?? null) ? (string) $item['name'] : null;
            $count = filter_var($item['count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($siteId === null || $eventType === null || $eventType === '' || strlen($eventType) > 32 || $name === null || $count === false) {
                $skipped++;

                continue;
            }

            $minute = $this->time($item['minute'] ?? null)->startOfMinute();
            $name = $this->truncate($name, $nameBytes);
            $key = implode('|', [$siteId, $eventType, sha1($name), $minute->timestamp]);
            $sites[$siteId] = true;

            $rows[$key] = [
                'bucket_date' => $minute->toDateString(),
                'minute' => $minute,
                'organization_id' => $organizationId,
                'site_id' => $siteId,
                'source_id' => $sourceId,
                'event_type' => $eventType,
                'name' => $name,
                'name_hash' => sha1($name),
                'count' => $count,
                'errors' => max(0, (int) ($item['errors'] ?? 0)),
                'p50_ms' => $this->number($item['p50_ms'] ?? 0),
                'p95_ms' => $this->number($item['p95_ms'] ?? 0),
                'max_ms' => $this->number($item['max_ms'] ?? 0),
            ];
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('insights_aggregates')->upsert(
                $chunk,
                ['site_id', 'event_type', 'name_hash', 'minute', 'source_id'],
                ['count', 'errors', 'p50_ms', 'p95_ms', 'max_ms'],
            );
        }

        return count($rows);
    }

    /**
     * @param  list<string>  $siteIds
     */
    private function touchSites(string $organizationId, array $siteIds): void
    {
        if ($siteIds === []) {
            return;
        }

        $now = now();

        DB::table('insights_sites')->upsert(
            array_map(fn (string $siteId) => ['organization_id' => $organizationId, 'site_id' => $siteId, 'first_seen_at' => $now, 'last_seen_at' => $now], $siteIds),
            ['organization_id', 'site_id'],
            ['last_seen_at'],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function siteId(array $item): ?string
    {
        $siteId = $item['site_id'] ?? null;

        // Stored lowercase, like every Laravel HasUlids key (the Sites module's ids).
        return is_string($siteId) && preg_match(self::ULID, strtoupper($siteId)) === 1 ? strtolower($siteId) : null;
    }

    private function time(mixed $value): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');

        if (! is_string($value) || $value === '') {
            return $now;
        }

        try {
            $at = CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return $now;
        }

        // Clock skew: never accept timestamps from the future.
        return $at->greaterThan($now->addMinutes(5)) ? $now : $at;
    }

    private function hexId(mixed $value, int $length): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower($value);

        return preg_match('/^[0-9a-f]{'.$length.'}$/', $value) === 1 && trim($value, '0') !== '' ? $value : null;
    }

    private function number(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return is_finite($number) ? max(0.0, $number) : 0.0;
    }

    private function truncate(string $value, int $bytes): string
    {
        return strlen($value) <= $bytes ? $value : mb_strcut($value, 0, $bytes);
    }
}
