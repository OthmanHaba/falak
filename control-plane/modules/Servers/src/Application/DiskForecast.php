<?php

namespace Falak\Servers\Application;

/**
 * When a disk fills, from a least-squares line through its usage samples (the last six hours). Only a clear, rising
 * trend forecasts: too few samples or too short a span is unknown; a flat or falling line, or one that explains little
 * of the variation (noise, R² below MIN_R2), never fills.
 */
final class DiskForecast
{
    public const MIN_SAMPLES = 20;

    /** The samples must span at least this many seconds (half of the six-hour window). */
    public const MIN_SPAN = 3 * 3600;

    public const MIN_R2 = 0.6;

    /**
     * @param  list<array{0: int, 1: int}>  $samples  [unix seconds, used bytes], any order
     * @param  int  $capacity  used + available bytes
     * @return array{known: bool, hours: ?float} hours until full from $now (null: not filling); known = enough data to say
     */
    public static function hoursUntilFull(array $samples, int $capacity, int $now): array
    {
        $n = count($samples);

        if ($n < self::MIN_SAMPLES || $capacity <= 0) {
            return ['known' => false, 'hours' => null];
        }

        $ts = array_column($samples, 0);

        if (max($ts) - min($ts) < self::MIN_SPAN) {
            return ['known' => false, 'hours' => null];
        }

        // Centre time for numerical stability.
        $t0 = array_sum($ts) / $n;
        $meanY = array_sum(array_column($samples, 1)) / $n;
        $sxx = $sxy = $syy = 0.0;

        foreach ($samples as [$t, $y]) {
            $dx = $t - $t0;
            $dy = $y - $meanY;
            $sxx += $dx * $dx;
            $sxy += $dx * $dy;
            $syy += $dy * $dy;
        }

        if ($sxx <= 0.0 || $sxy <= 0.0 || $syy <= 0.0) {
            return ['known' => true, 'hours' => null];
        }

        $slope = $sxy / $sxx; // bytes per second
        $r2 = ($sxy * $sxy) / ($sxx * $syy);

        if ($r2 < self::MIN_R2) {
            return ['known' => true, 'hours' => null];
        }

        $usedNow = $meanY + $slope * ($now - $t0);

        return ['known' => true, 'hours' => max(0.0, ($capacity - $usedNow) / $slope / 3600)];
    }
}
