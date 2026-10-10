<?php

namespace Falak\Databases\Contracts;

use Illuminate\Support\Carbon;

/**
 * How often a backup schedule's latest backup is restored into a throwaway instance and checked (database and volume
 * schedules alike). Production environments drill weekly unless told otherwise; others don't.
 */
enum DrillFrequency: string
{
    case Off = 'off';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public static function default(bool $production): self
    {
        return $production ? self::Weekly : self::Off;
    }

    /** The next drill after $after: a week or a month later. */
    public function next(?Carbon $after = null): ?Carbon
    {
        $after = ($after ?? now())->copy();

        return match ($this) {
            self::Off => null,
            self::Weekly => $after->addWeek(),
            self::Monthly => $after->addMonthNoOverflow(),
        };
    }
}
