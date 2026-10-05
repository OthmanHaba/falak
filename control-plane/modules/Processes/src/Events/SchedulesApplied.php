<?php

namespace Falak\Processes\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Falak\Processes\Contracts\Data\ScheduledJobData;

/**
 * A server converged to its desired schedule set (cron.apply finished). `$jobs` is the complete set.
 */
final class SchedulesApplied
{
    use Dispatchable;

    /**
     * @param  list<ScheduledJobData>  $jobs
     */
    public function __construct(
        public string $serverId,
        public string $organizationId,
        public array $jobs,
    ) {}
}
