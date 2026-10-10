<?php

namespace Falak\Deployments\Application\Jobs;

use Falak\Deployments\Application\Watch\ReleaseWatcher;
use Falak\Deployments\Domain\Enums\WatchStatus;
use Falak\Deployments\Domain\Models\ReleaseWatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every 30 s: one round of every open watch window after a release went live (health checks through the edge, the
 * 5xx rate), ending the windows whose time is up.
 */
final class EvaluateReleaseWatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(ReleaseWatcher $watcher): void
    {
        foreach (ReleaseWatch::query()->where('status', WatchStatus::Watching)->orderBy('ends_at')->get() as $watch) {
            try {
                $watcher->evaluate($watch);
            } catch (Throwable $e) {
                Log::warning('deployments: release watch round failed', ['deployment' => $watch->deployment_id, 'error' => $e->getMessage()]);
            }
        }
    }
}
