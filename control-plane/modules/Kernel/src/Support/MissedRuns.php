<?php

namespace Falak\Kernel\Support;

use Carbon\CarbonImmutable;
use Cron\CronExpression;

/**
 * Whether a cron schedule (UTC) missed its last two runs: nothing succeeded since the second-most-recent scheduled run,
 * and the most recent one had its grace period (10% of the gap between them, from 5 minutes to an hour) to finish.
 * Irregular schedules (weekdays only, "0 3 * * 1-5") are judged by their own runs, so a weekend is not a miss.
 */
final class MissedRuns
{
    public const MIN_GRACE_SECONDS = 300;

    public const MAX_GRACE_SECONDS = 3600;

    /**
     * @param  CarbonImmutable  $since  the last success, or when the schedule was created
     */
    public static function twice(string $cron, CarbonImmutable $since, CarbonImmutable $now): bool
    {
        $expression = new CronExpression(trim($cron));
        $latest = CarbonImmutable::instance($expression->getPreviousRunDate($now->utc(), 0, true, 'UTC'));
        $before = CarbonImmutable::instance($expression->getPreviousRunDate($now->utc(), 1, true, 'UTC'));
        $grace = min(self::MAX_GRACE_SECONDS, max(self::MIN_GRACE_SECONDS, intdiv((int) $before->diffInSeconds($latest), 10)));

        return $since < $before && $now >= $latest->addSeconds($grace);
    }
}
