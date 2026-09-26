<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Kiln\Identity\Contracts\Role;
use Kiln\Insights\Application\HeartbeatTracker;
use Kiln\Insights\Contracts\IssueKind;
use Kiln\Insights\Contracts\IssueStatus;
use Kiln\Insights\Domain\Models\HeartbeatMonitor;
use Kiln\Insights\Domain\Models\Issue;
use Kiln\Insights\Events\HeartbeatMissed;
use Kiln\Insights\Events\IssueOpened;
use Kiln\Insights\Events\IssueResolved;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-27T10:00:20Z'));
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
});

it('tracks runs and expects the next scheduled slot', function () {
    insights_ingest($this->organization->id, [insights_heartbeat(['scheduled_at' => '2026-09-27T10:00:00Z', 'at' => '2026-09-27T10:00:05Z'])]);

    $monitor = HeartbeatMonitor::query()->sole();
    expect($monitor)
        ->job->toBe('shop-schedule')
        ->site_id->toBe(INSIGHTS_SITE)
        ->server_id->toBe('01J8SERVER0000000000000000')
        ->schedule->toBe('*/5 * * * *')
        ->last_status->toBe('finished')
        ->last_duration_ms->toBe(850)
        ->and($monitor->next_expected_at->toIso8601ZuluString())->toBe('2026-09-27T10:05:00Z')
        ->and($monitor->runs()->count())->toBe(1);
});

it('detects a missed run after the grace period, opens one issue and recovers on the next heartbeat', function () {
    Event::fake([HeartbeatMissed::class, IssueOpened::class, IssueResolved::class]);
    insights_ingest($this->organization->id, [insights_heartbeat(['scheduled_at' => '2026-09-27T10:00:00Z'])]);
    $tracker = app(HeartbeatTracker::class);

    // Expected 10:05, grace 120s → not missed before 10:07.
    expect($tracker->detectMissed(CarbonImmutable::parse('2026-09-27T10:06:30Z')))->toBe(0);
    Event::assertNotDispatched(HeartbeatMissed::class);

    expect($tracker->detectMissed(CarbonImmutable::parse('2026-09-27T10:07:30Z')))->toBe(1);

    $monitor = HeartbeatMonitor::query()->sole();
    $issue = Issue::query()->sole();
    expect($issue->kind)->toBe(IssueKind::Heartbeat)
        ->and($issue->title)->toBe('Scheduled task shop-schedule missed its run')
        ->and($issue->meta)->toMatchArray(['reason' => 'missed', 'expected_at' => '2026-09-27T10:05:00+00:00', 'monitor_id' => $monitor->id])
        ->and($monitor->missed_at)->not->toBeNull()
        ->and($monitor->next_expected_at->toIso8601ZuluString())->toBe('2026-09-27T10:10:00Z');

    Event::assertDispatched(HeartbeatMissed::class, fn (HeartbeatMissed $e) => $e->monitorId === $monitor->id
        && $e->issueId === $issue->id
        && $e->job === 'shop-schedule'
        && $e->organizationId === $this->organization->id
        && $e->expectedAt->format(DATE_ATOM) === '2026-09-27T10:05:00+00:00');

    // Still down at the next slot: the open issue counts it, no second alert event.
    expect($tracker->detectMissed(CarbonImmutable::parse('2026-09-27T10:12:30Z')))->toBe(1);
    expect($issue->refresh()->occurrences)->toBe(2);
    Event::assertDispatchedTimes(HeartbeatMissed::class, 1);

    // The job runs again → the missed issue resolves automatically.
    $this->travelTo(CarbonImmutable::parse('2026-09-27T10:15:04Z'));
    insights_ingest($this->organization->id, [insights_heartbeat(['scheduled_at' => '2026-09-27T10:15:00Z'])]);

    expect($issue->refresh()->status)->toBe(IssueStatus::Resolved)
        ->and($issue->resolved_by)->toBeNull()
        ->and(HeartbeatMonitor::query()->sole()->missed_at)->toBeNull();
    Event::assertDispatched(IssueResolved::class, fn ($e) => $e->issueId === $issue->id);

    // Missing again later regresses the same issue.
    expect($tracker->detectMissed(CarbonImmutable::parse('2026-09-27T10:23:00Z')))->toBe(1);
    expect($issue->refresh()->status)->toBe(IssueStatus::Open)->and($issue->regressions)->toBe(1);
    Event::assertDispatchedTimes(HeartbeatMissed::class, 2);
});

it('respects per-monitor grace, disabled monitors and schedules it cannot parse', function () {
    insights_ingest($this->organization->id, [
        insights_heartbeat(['job' => 'graceful', 'scheduled_at' => '2026-09-27T10:00:00Z']),
        insights_heartbeat(['job' => 'disabled', 'scheduled_at' => '2026-09-27T10:00:00Z']),
        insights_heartbeat(['job' => 'unparseable', 'schedule' => 'whenever', 'scheduled_at' => '2026-09-27T10:00:00Z']),
    ]);
    HeartbeatMonitor::query()->where('job', 'graceful')->update(['grace_seconds' => 3600]);
    HeartbeatMonitor::query()->where('job', 'disabled')->update(['enabled' => false]);

    expect(app(HeartbeatTracker::class)->detectMissed(CarbonImmutable::parse('2026-09-27T10:30:00Z')))->toBe(0)
        ->and(HeartbeatMonitor::query()->where('job', 'unparseable')->value('next_expected_at'))->toBeNull();
});

it('opens a failure issue for failed runs and resolves it on the next success', function () {
    Event::fake([IssueOpened::class, IssueResolved::class]);
    insights_ingest($this->organization->id, [insights_heartbeat(['status' => 'failed', 'exit_code' => 1, 'scheduled_at' => '2026-09-27T10:00:00Z'])]);

    $issue = Issue::query()->sole();
    expect($issue->title)->toBe('Scheduled task shop-schedule failed')->and($issue->meta['exit_code'])->toBe(1);

    insights_ingest($this->organization->id, [insights_heartbeat(['status' => 'timeout', 'scheduled_at' => '2026-09-27T10:05:00Z', 'at' => '2026-09-27T10:06:00Z'])]);
    expect($issue->refresh()->occurrences)->toBe(2);

    // A skipped (overlap) run does not clear a failure; a finished one does.
    insights_ingest($this->organization->id, [insights_heartbeat(['status' => 'skipped', 'scheduled_at' => '2026-09-27T10:10:00Z', 'at' => '2026-09-27T10:10:00Z'])]);
    expect($issue->refresh()->status)->toBe(IssueStatus::Open);

    insights_ingest($this->organization->id, [insights_heartbeat(['scheduled_at' => '2026-09-27T10:15:00Z', 'at' => '2026-09-27T10:15:01Z'])]);
    expect($issue->refresh()->status)->toBe(IssueStatus::Resolved);
    Event::assertDispatchedTimes(IssueOpened::class, 1);
});

it('ignores out-of-order heartbeats for the monitor state', function () {
    insights_ingest($this->organization->id, [insights_heartbeat(['scheduled_at' => '2026-09-27T10:00:00Z'])]);
    insights_ingest($this->organization->id, [insights_heartbeat(['status' => 'failed', 'scheduled_at' => '2026-09-27T09:55:00Z'])]);

    $monitor = HeartbeatMonitor::query()->sole();
    expect($monitor->last_status)->toBe('finished')
        ->and($monitor->runs()->count())->toBe(2)
        ->and(Issue::query()->count())->toBe(0);
});

it('lets members configure monitors', function () {
    insights_ingest($this->organization->id, [insights_heartbeat(['scheduled_at' => '2026-09-27T10:00:00Z'])]);
    $monitor = HeartbeatMonitor::query()->sole();

    $this->get('/insights/heartbeats')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Insights/Heartbeats', false)
        ->where('monitors.0.job', 'shop-schedule')
        ->where('monitors.0.healthy', true));

    $this->put("/insights/heartbeats/{$monitor->id}", ['schedule' => '@hourly', 'grace_seconds' => 600, 'timezone' => 'Europe/Berlin'])->assertSessionHasNoErrors();
    expect($monitor->refresh())->schedule->toBe('@hourly')->grace_seconds->toBe(600)
        ->and($monitor->next_expected_at->toIso8601ZuluString())->toBe('2026-09-27T11:00:00Z');

    $this->put("/insights/heartbeats/{$monitor->id}", ['schedule' => 'nope', 'timezone' => 'Mars/Olympus'])->assertSessionHasErrors(['schedule', 'timezone']);

    actingAsMember();
    $this->put("/insights/heartbeats/{$monitor->id}", ['enabled' => false])->assertNotFound();

    $this->actingAs($this->user)->delete("/insights/heartbeats/{$monitor->id}")->assertRedirect();
    expect(HeartbeatMonitor::query()->count())->toBe(0);
});
