<?php

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\QuietHours;

it('handles same-day windows', function () {
    $quiet = QuietHours::fromArray(['start' => '12:00', 'end' => '13:30', 'timezone' => 'UTC']);

    expect($quiet->contains(CarbonImmutable::parse('2026-09-28 12:00', 'UTC')))->toBeTrue()
        ->and($quiet->contains(CarbonImmutable::parse('2026-09-28 13:29', 'UTC')))->toBeTrue()
        ->and($quiet->contains(CarbonImmutable::parse('2026-09-28 13:30', 'UTC')))->toBeFalse()
        ->and($quiet->contains(CarbonImmutable::parse('2026-09-28 11:59', 'UTC')))->toBeFalse();
});

it('handles overnight windows in the configured timezone', function () {
    // 22:00–07:00 Berlin (UTC+2 in September).
    $quiet = QuietHours::fromArray(['start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/Berlin']);

    expect($quiet->contains(CarbonImmutable::parse('2026-09-28 20:30', 'UTC')))->toBeTrue()   // 22:30 local
        ->and($quiet->contains(CarbonImmutable::parse('2026-09-29 04:59', 'UTC')))->toBeTrue()  // 06:59 local
        ->and($quiet->contains(CarbonImmutable::parse('2026-09-29 05:00', 'UTC')))->toBeFalse() // 07:00 local
        ->and($quiet->contains(CarbonImmutable::parse('2026-09-28 19:59', 'UTC')))->toBeFalse();
});

it('limits windows to the weekday they start on', function () {
    // Friday night only (ISO 5); 2026-10-02 is a Friday.
    $quiet = QuietHours::fromArray(['start' => '22:00', 'end' => '06:00', 'timezone' => 'UTC', 'days' => [5]]);

    expect($quiet->contains(CarbonImmutable::parse('2026-10-02 23:00', 'UTC')))->toBeTrue()
        ->and($quiet->contains(CarbonImmutable::parse('2026-10-03 03:00', 'UTC')))->toBeTrue()  // Saturday morning, Friday's window
        ->and($quiet->contains(CarbonImmutable::parse('2026-10-03 23:00', 'UTC')))->toBeFalse() // Saturday night
        ->and($quiet->contains(CarbonImmutable::parse('2026-10-01 23:00', 'UTC')))->toBeFalse();
});

it('lets critical alerts through unless configured otherwise', function () {
    $at = CarbonImmutable::parse('2026-09-28 23:00', 'UTC');

    expect(QuietHours::fromArray(['start' => '22:00', 'end' => '06:00'])->suppresses(Severity::Critical, $at))->toBeFalse()
        ->and(QuietHours::fromArray(['start' => '22:00', 'end' => '06:00'])->suppresses(Severity::Warning, $at))->toBeTrue()
        ->and(QuietHours::fromArray(['start' => '22:00', 'end' => '06:00', 'allow_critical' => false])->suppresses(Severity::Critical, $at))->toBeTrue();
});
