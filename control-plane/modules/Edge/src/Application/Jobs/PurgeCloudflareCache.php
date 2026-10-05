<?php

namespace Falak\Edge\Application\Jobs;

use Falak\Edge\Application\CloudflareEdgeControls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Purges a site's names at Cloudflare (after a deploy or rollback); a no-op outside managed zones. */
final class PurgeCloudflareCache implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $siteId) {}

    public function handle(CloudflareEdgeControls $controls): void
    {
        $controls->purgeSite($this->siteId);
    }
}
