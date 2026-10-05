<?php

namespace Falak\Builds\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Falak\Builds\Application\Artifacts\ArtifactStorage;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Models\BuildLog;

/**
 * Daily retention: keep the newest N artifacts per site (and none older than max_age_days), delete
 * the rest from storage, and drop old build log lines. Reused builds share their origin's artifact,
 * so an object is deleted only when no retained build references it.
 */
final class PruneArtifacts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(ArtifactStorage $storage): void
    {
        $keep = max(1, (int) config('builds.artifacts.keep_per_site', 10));
        $cutoff = now()->subDays(max(1, (int) config('builds.artifacts.max_age_days', 90)));

        $siteIds = Build::query()->where('status', BuildStatus::Succeeded)->whereNull('artifact_pruned_at')->distinct()->pluck('site_id');

        foreach ($siteIds as $siteId) {
            $builds = Build::query()->where('site_id', $siteId)->where('status', BuildStatus::Succeeded)->whereNull('artifact_pruned_at')
                ->orderByDesc('created_at')->orderByDesc('id')->get();

            // Distinct artifacts (a reused build shares its origin's), newest first.
            $artifacts = $builds->toBase()->groupBy(fn (Build $b) => $b->reused_build_id ?? $b->id);
            $retained = $artifacts->take($keep)->filter(fn ($group) => $group->first()->created_at->greaterThan($cutoff));

            foreach ($artifacts->except($retained->keys()->all()) as $group) {
                foreach ($group as $build) {
                    /** @var Build $build */
                    if ($build->reused_build_id === null && $build->artifact_key !== null) {
                        $storage->delete($build->artifact_key);
                    }

                    $build->forceFill(['artifact_pruned_at' => now()])->save();
                }
            }
        }

        BuildLog::query()->where('at', '<', now()->subDays(max(1, (int) config('builds.log_retention_days', 30))))->delete();
    }
}
