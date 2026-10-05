<?php

namespace Falak\Processes\Contracts;

use Falak\Processes\Contracts\Data\SourcedJob;
use Falak\Sites\Contracts\Data\SiteData;

/**
 * Scheduled jobs other modules run on a site's servers (a function's schedules), compiled into the server's
 * cron.apply next to Processes' own: same overlap, timeout and heartbeat handling, so their runs reach Insights
 * (history, missed runs) like any scheduled job. Owners call ProcessControl::converge() when theirs change.
 */
interface ScheduleSources
{
    /**
     * @return list<SourcedJob>
     */
    public function jobs(SiteData $site, string $serverId, bool $isLeader): array;
}
