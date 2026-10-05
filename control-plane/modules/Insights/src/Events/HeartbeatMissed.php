<?php

namespace Falak\Insights\Events;

use DateTimeImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A scheduled task did not report a run by its expected time (+ grace).
 */
final class HeartbeatMissed
{
    use Dispatchable;

    public function __construct(
        public string $organizationId,
        public ?string $siteId,
        public ?string $serverId,
        public string $monitorId,
        public string $issueId,
        public string $job,
        public ?string $schedule,
        public DateTimeImmutable $expectedAt,
        public ?DateTimeImmutable $lastRunAt,
        public string $url,
    ) {}
}
