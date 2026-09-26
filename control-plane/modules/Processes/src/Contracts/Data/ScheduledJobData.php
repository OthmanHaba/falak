<?php

namespace Kiln\Processes\Contracts\Data;

final readonly class ScheduledJobData
{
    /**
     * @param  string  $name  cron.apply job name (= heartbeat `job`)
     * @param  string  $kind  "scheduler" (Laravel schedule:run) or "custom"
     * @param  string  $schedule  cron expression, preset or `@every <duration>`
     */
    public function __construct(
        public string $name,
        public string $serverId,
        public string $organizationId,
        public string $siteId,
        public string $kind,
        public string $label,
        public string $schedule,
        public string $timezone,
        public bool $heartbeat,
    ) {}
}
