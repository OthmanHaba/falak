<?php

namespace Falak\Insights\Application\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Falak\Insights\Application\IssueTracker;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Domain\Enums\ThresholdMetric;
use Falak\Insights\Domain\Models\Threshold;
use Falak\Insights\Events\ThresholdBreached;

/**
 * Evaluates every enabled threshold over its window of complete minutes. p95 over a window is the
 * count-weighted mean of the per-minute p95s (exact percentiles cannot be merged); max is exact.
 * A breach opens (or reopens) one performance issue per threshold + name.
 */
final class EvaluateThresholds
{
    public function __construct(private readonly IssueTracker $issues) {}

    /**
     * @return int number of breaching (threshold, name) pairs
     */
    public function __invoke(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $breaches = 0;

        Threshold::query()->where('enabled', true)->orderBy('id')->chunkById(100, function ($thresholds) use ($now, &$breaches) {
            foreach ($thresholds as $threshold) {
                $breaches += $this->evaluate($threshold, $now);
            }
        });

        return $breaches;
    }

    public function evaluate(Threshold $threshold, CarbonImmutable $now): int
    {
        $end = $now->startOfMinute();
        $start = $end->subMinutes(max(1, $threshold->window_minutes));

        $query = DB::table('insights_aggregates')
            ->where('organization_id', $threshold->organization_id)
            ->where('site_id', $threshold->site_id)
            ->where('event_type', $threshold->event_type->value)
            ->where('minute', '>=', $start)
            ->where('minute', '<', $end)
            ->groupBy('name_hash', 'name')
            ->havingRaw('SUM(count) >= ?', [max(1, $threshold->min_count)])
            ->selectRaw('name, SUM(count) AS total, MAX(max_ms) AS max_ms, SUM(p95_ms * count) AS weighted_p95');

        if (($like = $threshold->likePattern()) !== null) {
            $query->whereRaw("name LIKE ? ESCAPE '\\'", [$like]);
        }

        $breaching = [];

        foreach ($query->get() as $row) {
            $total = (int) $row->total;
            $value = $threshold->metric === ThresholdMetric::Max ? (float) $row->max_ms : ($total > 0 ? (float) $row->weighted_p95 / $total : 0.0);

            if ($value > $threshold->threshold_ms) {
                $breaching[] = ['name' => (string) $row->name, 'value' => round($value, 2), 'count' => $total];
            }
        }

        usort($breaching, fn (array $a, array $b) => $b['value'] <=> $a['value']);
        $breaching = array_slice($breaching, 0, (int) config('insights.thresholds.max_breaches_per_evaluation', 25));

        foreach ($breaching as $breach) {
            $this->breach($threshold, $breach, $now);
        }

        $threshold->forceFill(['last_evaluated_at' => $now] + ($breaching !== [] ? ['last_breached_at' => $now] : []))->save();

        return count($breaching);
    }

    /**
     * @param  array{name: string, value: float, count: int}  $breach
     */
    private function breach(Threshold $threshold, array $breach, CarbonImmutable $now): void
    {
        [$issue, $transition] = $this->issues->record(
            organizationId: $threshold->organization_id,
            kind: IssueKind::Performance,
            fingerprint: hash('sha256', "threshold\n{$threshold->id}\n{$breach['name']}"),
            attributes: [
                'site_id' => $threshold->site_id,
                'title' => mb_strcut('Slow '.$threshold->event_type->noun().': '.$breach['name'], 0, 500),
                'culprit' => $threshold->describe(),
                'event_type' => $threshold->event_type->value,
                'meta' => [
                    'threshold_id' => $threshold->id,
                    'name' => $breach['name'],
                    'metric' => $threshold->metric->value,
                    'value_ms' => $breach['value'],
                    'threshold_ms' => $threshold->threshold_ms,
                    'window_minutes' => $threshold->window_minutes,
                    'count' => $breach['count'],
                ],
            ],
            firstAt: $now,
            lastAt: $now,
        );

        if (in_array($transition, [IssueTracker::OPENED, IssueTracker::REGRESSED], true)) {
            ThresholdBreached::dispatch(
                $threshold->organization_id,
                $threshold->site_id,
                $threshold->id,
                $issue->id,
                $threshold->event_type->value,
                $breach['name'],
                $threshold->metric->value,
                $breach['value'],
                $threshold->threshold_ms,
                $threshold->window_minutes,
                $issue->url(),
            );
        }
    }
}
