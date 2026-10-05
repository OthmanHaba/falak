<?php

namespace Falak\Processes\Contracts\Data;

use Falak\Processes\Contracts\CronExpressions;
use Falak\Processes\Contracts\ScheduleSources;

/**
 * One scheduled job a module adds to a server's cron.apply ({@see ScheduleSources}).
 */
final readonly class SourcedJob
{
    /**
     * @param  string  $key  unique per site and kind ([a-z0-9-], short): the cron job is `<site slug>.<kind>-<key>`
     * @param  string  $kind  e.g. "function" (ScheduledJobData::$kind)
     * @param  string  $schedule  cron expression, preset or `@every <duration>` ({@see CronExpressions})
     * @param  string  $command  run via /bin/sh -c
     * @param  'allow'|'skip'  $overlap
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $label,
        public string $schedule,
        public string $timezone,
        public string $command,
        public string $user,
        public string $cwd = '/',
        public string $overlap = 'skip',
        public int $timeoutSeconds = 300,
        public bool $heartbeat = true,
    ) {}
}
