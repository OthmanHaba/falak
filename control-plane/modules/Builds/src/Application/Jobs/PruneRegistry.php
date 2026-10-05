<?php

namespace Falak\Builds\Application\Jobs;

use Falak\Builds\Application\RegistryPruner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily, after PruneArtifacts: deletes the registry images of builds whose artifact was pruned, that no release may
 * still run (RegistryPruner). `falak-ctl registry gc` (weekly) then reclaims the space.
 */
final class PruneRegistry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(RegistryPruner $pruner): void
    {
        $pruner->prune();
    }
}
