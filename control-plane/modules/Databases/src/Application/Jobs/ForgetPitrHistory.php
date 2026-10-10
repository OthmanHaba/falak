<?php

namespace Falak\Databases\Application\Jobs;

use Falak\Databases\Application\Actions\PrunePitr;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A deleted instance's point-in-time recovery history (bases, segments, gaps) is deleted from storage and the database:
 * nothing can be restored from it any more. What storage refuses now is retried by MaintainPitr's hourly sweep.
 */
final class ForgetPitrHistory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $instanceId) {}

    public function handle(PrunePitr $prune): void
    {
        $prune->forget($this->instanceId);
    }
}
