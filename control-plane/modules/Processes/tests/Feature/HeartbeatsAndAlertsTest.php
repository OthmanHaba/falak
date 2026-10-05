<?php

use Illuminate\Support\Carbon;
use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Fleet\Events\InsightsReceived;
use Falak\Identity\Contracts\Role;
use Falak\Insights\Application\HeartbeatTracker;
use Falak\Insights\Domain\Models\HeartbeatMonitor;
use Falak\Processes\Application\Jobs\PollProcessStatus;
use Falak\Processes\Application\ServerConverger;
use Falak\Processes\Application\StatusPoller;
use Falak\Processes\Contracts\ScheduleDirectory;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Processes\Events\ProgramCrashLooping;
use Falak\Processes\Events\ProgramRecovered;

require_once __DIR__.'/../Support/helpers.php';

final class RecordingAlerts implements Alerts
{
    /** @var list<AlertData> */
    public array $raised = [];

    public function raise(AlertData $alert): void
    {
        $this->raised[] = $alert;
    }
}

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = processes_fake_agents();
    $this->server = processes_server($this->organization->id);
    $this->alerts = new RecordingAlerts;
    app()->instance(Alerts::class, $this->alerts);
});

function processes_apply(object $test): void
{
    app(ServerConverger::class)->converge($test->server->id);

    foreach (['proc.apply', 'cron.apply'] as $type) {
        if ($test->agents->dispatched($type) !== []) {
            $test->agents->succeed($test->agents->last($type)['handle'], $type === 'cron.apply' ? ['changed' => true, 'jobs' => []] : ['changed' => true]);
        }
    }
}

it('exposes applied schedules to Insights, which attributes heartbeats and expects runs', function () {
    Carbon::setTestNow('2026-09-26 10:00:30');
    $site = processes_site($this->organization->id, [$this->server], ['laravel' => ['scheduler' => true]]);
    processes_apply($this);

    $directory = app(ScheduleDirectory::class);
    $job = $directory->find(strtoupper($this->server->id), 'shop.schedule');
    expect($job)->not->toBeNull()
        ->and($job->siteId)->toBe($site->id)
        ->and($job->schedule)->toBe('* * * * *')
        ->and($directory->manages($this->server->id))->toBeTrue();

    // SchedulesApplied seeded a monitor that expects the next minute even before any heartbeat.
    $monitor = HeartbeatMonitor::query()->where('job', 'shop.schedule')->sole();
    expect($monitor->site_id)->toBe($site->id)
        ->and($monitor->server_id)->toBe($this->server->id)
        ->and($monitor->next_expected_at->toIso8601String())->toBe('2026-09-26T10:01:00+00:00');

    // A heartbeat without site_id is attributed through the directory.
    InsightsReceived::dispatch('agent-1', $this->organization->id, $this->server->id, [
        ['kind' => 'cron_heartbeat', 'job' => 'shop.schedule', 'status' => 'finished', 'scheduled_at' => '2026-09-26T10:01:00Z', 'at' => '2026-09-26T10:01:02Z'],
    ]);
    expect($monitor->refresh()->last_status)->toBe('finished')->and($monitor->site_id)->toBe($site->id);

    // Turning the scheduler off stops the expectation instead of reporting missed runs.
    $this->put("/sites/{$site->id}/laravel", ['scheduler' => false, 'horizon' => false, 'octane' => false, 'maintenance' => false])->assertSessionHasNoErrors();
    $this->agents->succeed($this->agents->last('cron.apply')['handle'], ['changed' => true, 'jobs' => []]);

    expect($monitor->refresh()->next_expected_at)->toBeNull();

    Carbon::setTestNow('2026-09-26 11:00:00');
    expect(app(HeartbeatTracker::class)->detectMissed())->toBe(0);
});

it('does not report a removed job as missed even before the next apply is confirmed', function () {
    Carbon::setTestNow('2026-09-26 10:00:00');
    $site = processes_site($this->organization->id, [$this->server]);
    $schedule = Schedule::query()->create(['organization_id' => $this->organization->id, 'site_id' => $site->id, 'name' => 'Report', 'command' => 'true', 'expression' => '@hourly']);
    processes_apply($this);

    $monitor = HeartbeatMonitor::query()->sole();
    expect($monitor->next_expected_at)->not->toBeNull();

    $schedule->delete();
    app(ServerConverger::class)->converge($this->server->id); // dispatched, not yet confirmed

    Carbon::setTestNow('2026-09-26 12:00:00');
    expect(app(HeartbeatTracker::class)->detectMissed())->toBe(0)
        ->and($monitor->refresh()->next_expected_at)->toBeNull();
});

it('raises crash-loop alerts from proc.status and resolves them on recovery', function () {
    processes_site($this->organization->id, [$this->server], ['laravel' => ['horizon' => true]]);
    processes_apply($this);

    app()->call([new PollProcessStatus, 'handle']);
    $status = $this->agents->last('proc.status');
    expect(processes_schema_errors($status))->toBe([]);

    $this->agents->succeed($status['handle'], ['processes' => [
        ['name' => 'shop.horizon', 'instance' => 0, 'state' => 'backoff', 'restarts' => 7, 'last_exit_code' => 1],
    ]]);

    expect(ServerState::query()->find($this->server->id)->crash_looping)->toBe(['shop.horizon'])
        ->and($this->alerts->raised)->toHaveCount(1)
        ->and($this->alerts->raised[0]->type)->toBe(ProgramCrashLooping::ALERT_TYPE)
        ->and($this->alerts->raised[0]->dedupKey)->toBe("processes.program:{$this->server->id}:shop.horizon")
        ->and($this->alerts->raised[0]->url)->toContain('/queues');

    // Still crashing: no second alert.
    $this->agents->succeed(app(StatusPoller::class)->request($this->server->id), ['processes' => [
        ['name' => 'shop.horizon', 'instance' => 0, 'state' => 'fatal', 'restarts' => 9],
    ]]);
    expect($this->alerts->raised)->toHaveCount(1);

    $this->agents->succeed(app(StatusPoller::class)->request($this->server->id), ['processes' => [
        ['name' => 'shop.horizon', 'instance' => 0, 'state' => 'running', 'restarts' => 9],
    ]]);

    expect($this->alerts->raised)->toHaveCount(2)
        ->and($this->alerts->raised[1]->type)->toBe(ProgramRecovered::ALERT_TYPE)
        ->and($this->alerts->raised[1]->resolves)->toBeTrue()
        ->and(ServerState::query()->find($this->server->id)->crash_looping)->toBeNull();
});

it('ignores proc.status results of commands Processes did not request', function () {
    processes_site($this->organization->id, [$this->server], ['laravel' => ['horizon' => true]]);
    processes_apply($this);

    $foreign = $this->agents->dispatch($this->server->id, 'proc.status', (object) [], 30, 'someone-else');
    $this->agents->succeed($foreign, ['processes' => [['name' => 'shop.horizon', 'instance' => 0, 'state' => 'fatal', 'restarts' => 50]]]);

    expect($this->alerts->raised)->toBe([]);
});

it('registers its alert types for the rule editor', function () {
    expect(app(AlertTypes::class)->all())->toHaveKeys([ProgramCrashLooping::ALERT_TYPE, ProgramRecovered::ALERT_TYPE]);
});
