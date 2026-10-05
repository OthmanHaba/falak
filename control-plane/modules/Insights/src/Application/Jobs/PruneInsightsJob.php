<?php

namespace Falak\Insights\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Falak\Insights\Application\Actions\PruneInsights;

final class PruneInsightsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 3600;

    public function handle(PruneInsights $prune): void
    {
        $prune();
    }
}
