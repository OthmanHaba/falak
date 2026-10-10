<?php

namespace Falak\Deployments\Application\Jobs;

use Falak\Deployments\Domain\Enums\WatchStatus;
use Falak\Deployments\Domain\Models\ReleaseWatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Every 30 s: queue one round of every open watch window after a release went live (health checks through the edge,
 * the 5xx rate; ends the windows whose time is up). One job per window, so one slow site never holds up the others.
 */
final class EvaluateReleaseWatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        ReleaseWatch::query()->where('status', WatchStatus::Watching)->orderBy('ends_at')->pluck('deployment_id')
            ->each(fn (string $id) => EvaluateReleaseWatch::dispatch($id));
    }
}
