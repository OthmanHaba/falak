<?php

namespace Falak\Processes\Events;

use Falak\Processes\Contracts\Data\ScheduledJobData;
use Illuminate\Foundation\Events\Dispatchable;

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
