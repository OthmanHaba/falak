<?php

namespace Falak\Secrets\Application\Jobs;

use Falak\Secrets\Domain\Models\AccessLogEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes secret access log entries older than secrets.access_log_retention_days, in chunks.
 */
final class PruneAccessLog implements ShouldQueue
{
    use Queueable;

    private const CHUNK = 1000;

    public function handle(): void
    {
        $cutoff = now()->subDays(max(1, (int) config('secrets.access_log_retention_days', 730)));

        do {
            $ids = AccessLogEntry::query()->where('created_at', '<', $cutoff)->limit(self::CHUNK)->pluck('id');
            AccessLogEntry::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);
    }
}
