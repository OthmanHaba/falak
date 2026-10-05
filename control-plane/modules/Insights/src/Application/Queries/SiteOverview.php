<?php

namespace Falak\Insights\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Domain\Models\Issue;

/**
 * App overview dashboard computed from the per-minute aggregates and occurrences: for one site, a set of sites
 * (e.g. a project's), or the whole organization (`$sites = null`).
 */
final class SiteOverview
{
    /** range => [window minutes, bucket minutes] */
    public const RANGES = ['1h' => [60, 1], '6h' => [360, 5], '24h' => [1440, 15], '7d' => [10080, 120], '30d' => [43200, 720]];

    /**
     * @param  string|list<string>|null  $sites  one site, several, or null for every site of the organization
     * @return array<string, mixed>
     */
    public function __invoke(string $organizationId, string|array|null $sites, string $range, ?CarbonImmutable $now = null): array
    {
        [$window, $bucket] = self::RANGES[$range] ?? self::RANGES['24h'];
        $end = ($now ?? CarbonImmutable::now('UTC'))->utc()->startOfMinute()->addMinute();
        $start = $end->subMinutes($window);
        $scope = fn ($query) => self::scope($query, $sites);

        $aggregates = fn () => $scope(DB::table('insights_aggregates'))
            ->where('organization_id', $organizationId)
            ->where('minute', '>=', $start)
            ->where('minute', '<', $end);

        $series = $this->emptySeries($start, $end, $bucket);

        $perMinute = $aggregates()
            ->groupBy('minute', 'event_type')
            ->selectRaw('minute, event_type, SUM(count) AS total, SUM(errors) AS errors, SUM(p95_ms * count) AS weighted_p95')
            ->get();

        $totals = ['requests' => 0, 'request_errors' => 0, 'jobs' => 0, 'failed_jobs' => 0, 'queries' => 0, 'outgoing_requests' => 0, 'exceptions_handled' => 0, 'exceptions_unhandled' => 0];
        $requestP95Weight = 0.0;

        foreach ($perMinute as $row) {
            $key = $this->bucketKey($row->minute, $start, $bucket);
            $total = (int) $row->total;
            $errors = (int) $row->errors;

            switch ($row->event_type) {
                case 'request':
                    $series[$key]['requests'] += $total;
                    $series[$key]['request_errors'] += $errors;
                    $series[$key]['p95_weight'] += (float) $row->weighted_p95;
                    $totals['requests'] += $total;
                    $totals['request_errors'] += $errors;
                    $requestP95Weight += (float) $row->weighted_p95;
                    break;
                case 'job':
                    $series[$key]['jobs'] += $total;
                    $series[$key]['failed_jobs'] += $errors;
                    $totals['jobs'] += $total;
                    $totals['failed_jobs'] += $errors;
                    break;
                case 'query':
                    $series[$key]['queries'] += $total;
                    $totals['queries'] += $total;
                    break;
                case 'outgoing_request':
                    $totals['outgoing_requests'] += $total;
                    break;
            }
        }

        $exceptions = $scope(DB::table('insights_exceptions'))
            ->where('organization_id', $organizationId)
            ->where('minute', '>=', $start)
            ->where('minute', '<', $end)
            ->groupBy('minute', 'handled')
            ->selectRaw('minute, handled, COUNT(*) AS total')
            ->get();

        foreach ($exceptions as $row) {
            $key = $this->bucketKey($row->minute, $start, $bucket);
            $field = (bool) $row->handled ? 'exceptions_handled' : 'exceptions_unhandled';
            $series[$key][$field] += (int) $row->total;
            $totals[$field] += (int) $row->total;
        }

        $points = array_map(function (array $point) {
            $point['request_p95_ms'] = $point['requests'] > 0 ? round($point['p95_weight'] / $point['requests'], 1) : null;
            unset($point['p95_weight']);

            return $point;
        }, array_values($series));

        $cache = $aggregates()->where('event_type', 'cache')->groupBy('name')->selectRaw('name, SUM(count) AS total')->pluck('total', 'name');
        $hits = 0;
        $misses = 0;

        foreach ($cache as $name => $total) {
            // Cache aggregates are named after falak.cache.op, optionally suffixed with the store ("hit", "miss:redis").
            $op = strtolower(strtok((string) $name, ':') ?: '');
            $hits += $op === 'hit' ? (int) $total : 0;
            $misses += $op === 'miss' ? (int) $total : 0;
        }

        return [
            'range' => $range,
            'from' => $start->toIso8601String(),
            'to' => $end->toIso8601String(),
            'bucket_minutes' => $bucket,
            'totals' => [
                ...$totals,
                'request_p95_ms' => $totals['requests'] > 0 ? round($requestP95Weight / $totals['requests'], 1) : null,
                'cache_hit_ratio' => $hits + $misses > 0 ? round($hits / ($hits + $misses), 4) : null,
                'cache_hits' => $hits,
                'cache_misses' => $misses,
            ],
            'series' => $points,
            'routes' => $this->top($aggregates(), 'request', 'p95'),
            'jobs' => $this->top($aggregates(), 'job', 'total'),
            'queries' => $this->top($aggregates(), 'query', 'max'),
            'outgoing' => $this->top($aggregates(), 'outgoing_request', 'p95'),
            'issues' => $this->topIssues($organizationId, $sites, $start, $end),
        ];
    }

    /**
     * Restrict a query on a `site_id` column to one site, several, or (null) none.
     *
     * @param  string|list<string>|null  $sites
     */
    public static function scope(Builder|EloquentBuilder $query, string|array|null $sites): Builder|EloquentBuilder
    {
        return match (true) {
            $sites === null => $query,
            is_array($sites) => $query->whereIn('site_id', $sites),
            default => $query->where('site_id', $sites),
        };
    }

    /**
     * Open issues ranked by occurrences inside the window (then most recently seen), with their in-window count.
     *
     * @param  string|list<string>|null  $sites
     * @return list<array<string, mixed>>
     */
    private function topIssues(string $organizationId, string|array|null $sites, CarbonImmutable $start, CarbonImmutable $end, int $limit = 6): array
    {
        $counts = self::scope(DB::table('insights_exceptions'), $sites)
            ->where('organization_id', $organizationId)
            ->where('minute', '>=', $start)
            ->where('minute', '<', $end)
            ->groupBy('issue_id')
            ->selectRaw('issue_id, COUNT(*) AS total')
            ->orderByDesc('total')
            ->limit(50)
            ->pluck('total', 'issue_id');

        $open = fn () => self::scope(Issue::query(), $sites)->where('organization_id', $organizationId)->where('status', IssueStatus::Open);
        $issues = $open()->whereIn('id', $counts->keys()->all())->get()->keyBy('id');
        $ranked = $counts->keys()->filter(fn ($id) => $issues->has($id))->take($limit)->map(fn ($id) => $issues[$id])->values();

        if ($ranked->count() < $limit) {
            $ranked = $ranked->concat(
                $open()->whereNotIn('id', $ranked->pluck('id')->all())->orderByDesc('last_seen_at')->limit($limit - $ranked->count())->get()
            );
        }

        return $ranked->map(fn (Issue $issue) => [
            'id' => $issue->id,
            'kind' => $issue->kind->value,
            'title' => $issue->title,
            'culprit' => $issue->culprit,
            'site_id' => $issue->site_id,
            'occurrences' => $issue->occurrences,
            'occurrences_in_range' => (int) ($counts[$issue->id] ?? 0),
            'affected_users' => $issue->affected_users,
            'priority' => $issue->priority->value,
            'handled' => $issue->kind->value === 'exception' ? $issue->unhandled_occurrences === 0 : null,
            'last_seen_at' => $issue->last_seen_at->toIso8601String(),
        ])->all();
    }

    /**
     * @return list<array{name: string, count: int, errors: int, p95_ms: float, max_ms: float}>
     */
    private function top(Builder $query, string $eventType, string $order, int $limit = 10): array
    {
        $orderBy = match ($order) {
            'p95' => 'SUM(p95_ms * count) / SUM(count)',
            'max' => 'MAX(max_ms)',
            default => 'SUM(count)',
        };

        return $query->where('event_type', $eventType)
            ->groupBy('name_hash', 'name')
            ->havingRaw('SUM(count) > 0')
            ->selectRaw('name, SUM(count) AS total, SUM(errors) AS errors, SUM(p95_ms * count) / SUM(count) AS p95, MAX(max_ms) AS max_ms')
            ->orderByRaw("{$orderBy} DESC")
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'count' => (int) $row->total,
                'errors' => (int) $row->errors,
                'p95_ms' => round((float) $row->p95, 1),
                'max_ms' => round((float) $row->max_ms, 1),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function emptySeries(CarbonImmutable $start, CarbonImmutable $end, int $bucket): array
    {
        $series = [];

        for ($at = $start, $i = 0; $at->lessThan($end); $at = $at->addMinutes($bucket), $i++) {
            $series[$i] = [
                't' => $at->toIso8601String(),
                'requests' => 0,
                'request_errors' => 0,
                'p95_weight' => 0.0,
                'jobs' => 0,
                'failed_jobs' => 0,
                'queries' => 0,
                'exceptions_handled' => 0,
                'exceptions_unhandled' => 0,
            ];
        }

        return $series;
    }

    private function bucketKey(string $minute, CarbonImmutable $start, int $bucket): int
    {
        $at = CarbonImmutable::parse($minute, 'UTC');

        return max(0, intdiv((int) $start->diffInMinutes($at, true), $bucket));
    }
}
