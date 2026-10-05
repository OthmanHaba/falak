<?php

namespace Falak\Insights\Domain\Support;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Schedules accepted by cron.apply: 5-field cron, @hourly/@daily/@weekly/@monthly/@yearly
 * (and @annually/@midnight), or "@every <Go duration>" (e.g. 30s, 5m, 1h30m).
 */
final class CronSchedule
{
    private function __construct(
        private readonly ?CronExpression $cron,
        private readonly ?int $intervalSeconds,
        private readonly string $timezone,
    ) {}

    public static function parse(?string $expression, string $timezone = 'UTC'): ?self
    {
        $expression = trim((string) $expression);

        if ($expression === '') {
            return null;
        }

        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            $timezone = 'UTC';
        }

        if (str_starts_with($expression, '@every ')) {
            $seconds = self::goDuration(substr($expression, 7));

            return $seconds !== null && $seconds >= 1 ? new self(null, $seconds, $timezone) : null;
        }

        $normalized = $expression === '@midnight' ? '@daily' : $expression;

        if (! CronExpression::isValidExpression($normalized)) {
            return null;
        }

        return new self(new CronExpression($normalized), null, $timezone);
    }

    public static function isValid(?string $expression): bool
    {
        return self::parse($expression) !== null;
    }

    /** First scheduled run strictly after $after. */
    public function nextAfter(DateTimeInterface $after): CarbonImmutable
    {
        $after = CarbonImmutable::instance($after);

        if ($this->intervalSeconds !== null) {
            return $after->addSeconds($this->intervalSeconds);
        }

        /** @var CronExpression $cron */
        $cron = $this->cron;

        return CarbonImmutable::instance($cron->getNextRunDate($after->setTimezone($this->timezone), 0, false, $this->timezone))->utc();
    }

    /** Approximate period in seconds (used to size default grace). */
    public function periodSeconds(): int
    {
        if ($this->intervalSeconds !== null) {
            return $this->intervalSeconds;
        }

        $first = $this->nextAfter(CarbonImmutable::now());

        return max(1, $first->diffInSeconds($this->nextAfter($first), true));
    }

    private static function goDuration(string $value): ?int
    {
        $value = trim($value);

        if (preg_match('/^(?:\d+(?:\.\d+)?(?:h|m|s|ms))+$/', $value) !== 1) {
            return null;
        }

        preg_match_all('/(\d+(?:\.\d+)?)(h|ms|m|s)/', $value, $matches, PREG_SET_ORDER);
        $seconds = 0.0;

        foreach ($matches as [, $amount, $unit]) {
            $seconds += (float) $amount * match ($unit) {
                'h' => 3600,
                'm' => 60,
                's' => 1,
                'ms' => 0.001,
            };
        }

        return (int) floor($seconds);
    }
}
