<?php

use Carbon\CarbonImmutable;
use Falak\Kernel\Support\MissedRuns;

$at = fn (string $time) => CarbonImmutable::parse($time, 'UTC');

it('needs two missed runs and the grace period of the second', function () use ($at) {
    // Daily at 03:00 (grace 1 h), last success Wednesday 03:05.
    expect(MissedRuns::twice('0 3 * * *', $at('2026-10-07 03:05'), $at('2026-10-08 12:00')))->toBeFalse() // Thursday's run missed only
        ->and(MissedRuns::twice('0 3 * * *', $at('2026-10-07 03:05'), $at('2026-10-09 03:30')))->toBeFalse() // Friday's still in grace
        ->and(MissedRuns::twice('0 3 * * *', $at('2026-10-07 03:05'), $at('2026-10-09 04:00')))->toBeTrue();
});

it('judges weekday schedules by their own runs (weekends are not misses)', function () use ($at) {
    // Mon–Fri at 03:00; Friday 2026-10-09 succeeded; Monday 10:00 and Tuesday morning before the run.
    expect(MissedRuns::twice('0 3 * * 1-5', $at('2026-10-09 03:05'), $at('2026-10-12 10:00')))->toBeFalse()
        ->and(MissedRuns::twice('0 3 * * 1-5', $at('2026-10-09 03:05'), $at('2026-10-13 02:59')))->toBeFalse()
        ->and(MissedRuns::twice('0 3 * * 1-5', $at('2026-10-09 03:05'), $at('2026-10-13 04:00')))->toBeTrue();
});

it('scales the grace with short intervals', function () use ($at) {
    // Every 15 minutes: grace 5 minutes.
    expect(MissedRuns::twice('*/15 * * * *', $at('2026-10-10 11:40'), $at('2026-10-10 12:04')))->toBeFalse()
        ->and(MissedRuns::twice('*/15 * * * *', $at('2026-10-10 11:40'), $at('2026-10-10 12:05')))->toBeTrue()
        // A success after the second-most-recent run: fine.
        ->and(MissedRuns::twice('*/15 * * * *', $at('2026-10-10 11:46'), $at('2026-10-10 12:10')))->toBeFalse();
});
