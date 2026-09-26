<?php

namespace Kiln\Builds\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kiln\Builds\Application\BuildProgress;
use Kiln\Builds\Contracts\BuildStatus;
use Kiln\Builds\Domain\Models\Build;

/**
 * Watchdog (every minute): builds nobody picks up, builders that died after claiming a build, and
 * builds running past their timeout.
 */
final class ExpireBuilds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(BuildProgress $progress): void
    {
        $queueTtl = (int) config('builds.queue_ttl_seconds', 3600);
        Build::query()->where('status', BuildStatus::Queued)->where('created_at', '<', now()->subSeconds($queueTtl))->get()
            ->each(fn (Build $build) => $progress->fail($build, 'No builder picked up the build. Check that a builder is online.'));

        $assignTimeout = (int) config('builds.assign_timeout_seconds', 180);
        Build::query()->where('status', BuildStatus::Assigned)->where('assigned_at', '<', now()->subSeconds($assignTimeout))->get()
            ->each(function (Build $build) use ($progress) {
                if ($build->attempts >= (int) config('builds.max_attempts', 3)) {
                    $progress->fail($build, "The builder never started the build ({$build->attempts} attempts).");

                    return;
                }

                $requeued = Build::query()->whereKey($build->id)->where('status', BuildStatus::Assigned)
                    ->update(['status' => BuildStatus::Queued, 'builder_id' => null, 'assigned_at' => null, 'updated_at' => now()]);

                if ($requeued === 1) {
                    $progress->log($build->refresh(), ["The builder did not start the build; re-queued.\n"], 'stderr');
                }
            });

        $grace = (int) config('builds.grace_seconds', 120);
        Build::query()->where('status', BuildStatus::Running)->whereNotNull('started_at')->get()
            ->filter(fn (Build $build) => $build->started_at?->addSeconds($build->timeout_s + $grace)->isPast() ?? false)
            ->each(fn (Build $build) => $progress->fail($build, "Build timed out after {$build->timeout_s}s.", BuildStatus::TimedOut, BuildProgress::EXIT_TIMEOUT));
    }
}
