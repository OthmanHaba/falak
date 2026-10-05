<?php

namespace Falak\Processes\Contracts;

use Falak\Processes\Contracts\Data\ScheduledJobData;

/**
 * Scheduled jobs Processes sent to servers with cron.apply (the Laravel scheduler of each site and custom jobs).
 *
 * Insights uses it to attribute cron heartbeats to sites and to stop expecting runs of removed jobs.
 * Job names are unique per server; heartbeats carry them as `job`.
 */
interface ScheduleDirectory
{
    /**
     * Jobs in the desired schedule set of the server (empty when Processes never managed it).
     *
     * @return list<ScheduledJobData>
     */
    public function forServer(string $serverId): array;

    public function find(string $serverId, string $job): ?ScheduledJobData;

    /** Whether Processes manages the schedule set of the server (sent cron.apply at least once). */
    public function manages(string $serverId): bool;
}
