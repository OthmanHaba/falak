<?php

namespace Falak\Deployments\Application\Jobs;

use Falak\Deployments\Application\Watch\ReleaseWatcher;
use Falak\Deployments\Domain\Models\ReleaseWatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One round of one watch window ({@see ReleaseWatcher::evaluate()}). Unique per window while queued and never run
 * twice at once, so a slow round (a dead site's health checks time out) can't overlap the next one.
 */
final class EvaluateReleaseWatch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(public string $deploymentId) {}

    public function uniqueId(): string
    {
        return $this->deploymentId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("deployments:watch:{$this->deploymentId}"))->dontRelease()->expireAfter(180)];
    }

    public function handle(ReleaseWatcher $watcher): void
    {
        if (($watch = ReleaseWatch::query()->find($this->deploymentId)) !== null) {
            $watcher->evaluate($watch);
        }
    }
}
