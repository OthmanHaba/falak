<?php

namespace Falak\Insights\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Falak\Insights\Application\Actions\EvaluateThresholds;

final class EvaluateThresholdsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function handle(EvaluateThresholds $evaluate): void
    {
        $evaluate();
    }
}
