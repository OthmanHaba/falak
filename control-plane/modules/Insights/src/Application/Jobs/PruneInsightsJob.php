<?php

namespace Kiln\Insights\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Kiln\Insights\Application\Actions\PruneInsights;

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
