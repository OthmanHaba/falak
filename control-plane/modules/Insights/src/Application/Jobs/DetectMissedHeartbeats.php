<?php

namespace Falak\Insights\Application\Jobs;

use Falak\Insights\Application\HeartbeatTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class DetectMissedHeartbeats implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function handle(HeartbeatTracker $heartbeats): void
    {
        $heartbeats->detectMissed();
    }
}
