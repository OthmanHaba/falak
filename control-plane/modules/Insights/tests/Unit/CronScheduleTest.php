<?php

use Carbon\CarbonImmutable;
use Kiln\Insights\Domain\Support\CronSchedule;

it('computes the next run of cron expressions and macros', function (string $expression, string $after, string $expected) {
    expect(CronSchedule::parse($expression)?->nextAfter(CarbonImmutable::parse($after))->toIso8601ZuluString())->toBe($expected);
})->with([
    'every minute' => ['* * * * *', '2026-09-27T10:00:00Z', '2026-09-27T10:01:00Z'],
    'every 5 minutes' => ['*/5 * * * *', '2026-09-27T10:02:30Z', '2026-09-27T10:05:00Z'],
    'hourly' => ['@hourly', '2026-09-27T10:00:00Z', '2026-09-27T11:00:00Z'],
    'daily' => ['@daily', '2026-09-27T10:00:00Z', '2026-09-28T00:00:00Z'],
    'midnight' => ['@midnight', '2026-09-27T10:00:00Z', '2026-09-28T00:00:00Z'],
    'every 30s' => ['@every 30s', '2026-09-27T10:00:00Z', '2026-09-27T10:00:30Z'],
    'every 1h30m' => ['@every 1h30m', '2026-09-27T10:00:00Z', '2026-09-27T11:30:00Z'],
]);

it('honours the job timezone', function () {
    // 02:00 in Europe/Berlin (UTC+2 in September) is 00:00 UTC.
    $next = CronSchedule::parse('0 2 * * *', 'Europe/Berlin')->nextAfter(CarbonImmutable::parse('2026-09-27T10:00:00Z'));

    expect($next->toIso8601ZuluString())->toBe('2026-09-28T00:00:00Z');
});

it('rejects invalid schedules', function (?string $expression) {
    expect(CronSchedule::parse($expression))->toBeNull()->and(CronSchedule::isValid($expression))->toBeFalse();
})->with([null, '', 'not a cron', '@every', '@every 5x', '61 * * * *', '@every 0s']);

it('estimates the schedule period', function () {
    expect(CronSchedule::parse('*/5 * * * *')->periodSeconds())->toBe(300)
        ->and(CronSchedule::parse('@every 45s')->periodSeconds())->toBe(45);
});
