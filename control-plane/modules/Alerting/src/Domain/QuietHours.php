<?php

namespace Falak\Alerting\Domain;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Falak\Alerting\Contracts\Severity;

/**
 * A daily quiet window (e.g. 22:00–07:00 Europe/Berlin), optionally only on some ISO weekdays
 * (1 = Monday … 7 = Sunday, matched against the day the window starts).
 */
final readonly class QuietHours
{
    /**
     * @param  list<int>  $days  empty = every day
     */
    public function __construct(
        public int $startMinute,
        public int $endMinute,
        public string $timezone = 'UTC',
        public array $days = [],
        public bool $allowCritical = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::minutes((string) ($data['start'] ?? '00:00')),
            self::minutes((string) ($data['end'] ?? '00:00')),
            (string) ($data['timezone'] ?? 'UTC'),
            array_values(array_map('intval', (array) ($data['days'] ?? []))),
            (bool) ($data['allow_critical'] ?? true),
        );
    }

    public static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [1 => 0]);

        return ($hours * 60 + $minutes) % 1440;
    }

    /** Whether an alert of $severity is suppressed at $at. */
    public function suppresses(Severity $severity, DateTimeInterface $at): bool
    {
        if ($this->allowCritical && $severity === Severity::Critical) {
            return false;
        }

        return $this->contains($at);
    }

    public function contains(DateTimeInterface $at): bool
    {
        $local = CarbonImmutable::instance($at)->setTimezone(new DateTimeZone($this->timezone));
        $minute = $local->hour * 60 + $local->minute;

        if ($this->startMinute === $this->endMinute) {
            return $this->onDay($local);
        }

        if ($this->startMinute < $this->endMinute) {
            return $minute >= $this->startMinute && $minute < $this->endMinute && $this->onDay($local);
        }

        // Overnight window: the part after midnight belongs to the previous day's window.
        return ($minute >= $this->startMinute && $this->onDay($local))
            || ($minute < $this->endMinute && $this->onDay($local->subDay()));
    }

    private function onDay(CarbonImmutable $day): bool
    {
        return $this->days === [] || in_array($day->isoWeekday(), $this->days, true);
    }
}
