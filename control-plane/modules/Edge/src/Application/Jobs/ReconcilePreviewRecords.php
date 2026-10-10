<?php

namespace Falak\Edge\Application\Jobs;

use Falak\Edge\Application\PreviewRecords;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Every ten minutes: preview DNS records whose deletion failed are retried, and records of preview sites that no
 * longer exist are deleted (no dangling name for a subdomain takeover).
 */
final class ReconcilePreviewRecords implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(PreviewRecords $records): void
    {
        $records->reconcile();
    }
}
