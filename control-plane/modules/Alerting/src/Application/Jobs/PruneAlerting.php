<?php

namespace Falak\Alerting\Application\Jobs;

use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\DedupState;
use Falak\Alerting\Domain\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes alert history, deliveries, rule hits and notifications older than alerting.retention_days,
 * in chunks, plus resolved dedup states past retention.
 */
final class PruneAlerting implements ShouldQueue
{
    use Queueable;

    private const CHUNK = 1000;

    public function handle(): void
    {
        $cutoff = now()->subDays(max(1, (int) config('alerting.retention_days', 90)));

        DB::table('alerting_rule_hits')->where('created_at', '<', now()->subDay())->delete(); // only the last hour matters for rate limits

        do {
            $ids = Alert::query()->where('created_at', '<', $cutoff)->limit(self::CHUNK)->pluck('id');
            // Deliveries cascade with their alert.
            Alert::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        do {
            $ids = Notification::query()->where('created_at', '<', $cutoff)->limit(self::CHUNK)->pluck('id');
            Notification::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        DedupState::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $cutoff)->delete();
    }
}
