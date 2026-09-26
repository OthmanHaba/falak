<?php

namespace Kiln\Insights\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Kiln\Insights\Application\HeartbeatTracker;

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
