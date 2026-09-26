<?php

namespace Kiln\Insights\Application\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Deletes volume rows older than the retention window, by bucket_date and in bounded chunks
 * (portable: Postgres has no DELETE … LIMIT). With native daily partitions this becomes DROP PARTITION.
 */
final class PruneInsights
{
    private const TABLES = ['insights_exceptions', 'insights_aggregates', 'insights_heartbeat_runs'];

    /**
     * @return array<string, int> rows deleted per table
     */
    public function __invoke(?int $retentionDays = null): array
    {
        $days = max(1, $retentionDays ?? (int) config('insights.retention_days', 30));
        $cutoff = now()->utc()->subDays($days)->toDateString();
        $chunk = max(100, (int) config('insights.prune_chunk', 5000));
        $deleted = [];

        foreach (self::TABLES as $table) {
            $deleted[$table] = 0;

            do {
                $ids = DB::table($table)->where('bucket_date', '<', $cutoff)->orderBy('id')->limit($chunk)->pluck('id')->all();
                $count = $ids === [] ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
                $deleted[$table] += $count;
            } while ($count === $chunk);
        }

        return $deleted;
    }
}
