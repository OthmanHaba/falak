<?php

namespace Falak\Previews\Application\Jobs;

use Falak\Previews\Application\PreviewLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Deletes previews idle longer than their project's TTL (scheduled hourly). */
final class CleanupIdlePreviews implements ShouldQueue
{
    use Queueable;

    public function handle(PreviewLifecycle $lifecycle): void
    {
        $lifecycle->cleanupIdle();
    }
}
