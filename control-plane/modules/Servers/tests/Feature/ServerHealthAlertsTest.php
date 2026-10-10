<?php

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Models\Alert;
use Falak\Alerting\Domain\Models\Condition;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\AgentMetric;
use Falak\Fleet\Infrastructure\AgentBinaries;
use Falak\Servers\Application\Jobs\CheckServerHealth;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

const GIB = 1024 ** 3;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00'));
    [$this->owner, $this->organization] = memberOf();
    $this->server = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-1']);
    $this->agent = fleet_enroll($this->organization->id, $this->server->id, ['cpus' => 2, 'memory_bytes' => 8 * GIB, 'disk_bytes' => 100 * GIB])['agent'];
    $this->online = function (array $metrics = [], array $facts = []) {
        $this->agent->forceFill([
            'status' => AgentStatus::Online,
            'last_heartbeat_at' => now(),
            'facts' => [...(array) $this->agent->facts, ...$facts],
            'metrics' => ['at' => now()->toIso8601String(), 'load' => [0.1, 0.1, 0.1], 'cpu_percent' => 5.0, 'memory_used_bytes' => GIB, 'disk_used_bytes' => 10 * GIB, ...$metrics],
        ])->save();
    };
});

/** One sample every $every seconds over the last $minutes. */
function health_samples(Agent $agent, int $minutes, callable $row, int $every = 60): void
{
    for ($s = $minutes * 60; $s >= 0; $s -= $every) {
        AgentMetric::query()->create([
            'agent_id' => $agent->id, 'server_id' => $agent->server_id, 'at' => now()->subSeconds($s), 'uptime_s' => 1000,
            'load1' => 0.1, 'load5' => 0.1, 'load15' => 0.1, 'cpu_percent' => 5.0, 'memory_used_bytes' => GIB, 'disk_used_bytes' => 10 * GIB,
            ...$row($s),
        ]);
    }
}

function health_alerts(string $type, bool $recovery = false): Collection
{
    return Alert::query()->where('type', $type)->where('recovery', $recovery)->get();
}

/** Checks now and again once the disk hold time passed. */
function health_check_held(object $test, array $metrics): void
{
    ($test->online)($metrics);
    dispatch_sync(new CheckServerHealth);
    $test->travel(5)->minutes();
    ($test->online)($metrics);
    dispatch_sync(new CheckServerHealth);
}

it('alerts per mount at 80% and 90% once held for 5 minutes, and resolves 5 points below', function () {
    ($this->online)(['disks' => ['/' => [85 * GIB, 15 * GIB, 100 * GIB], '/data' => [10 * GIB, 90 * GIB, 100 * GIB]]]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.disk_usage'))->toHaveCount(0);
    $this->travel(5)->minutes();
    ($this->online)(['disks' => ['/' => [85 * GIB, 15 * GIB, 100 * GIB], '/data' => [10 * GIB, 90 * GIB, 100 * GIB]]]);
    dispatch_sync(new CheckServerHealth);

    $warning = health_alerts('servers.disk_usage')->sole();
    expect($warning->severity)->toBe(Severity::Warning)
        ->and($warning->title)->toBe('Disk / on web-1 is 85% full')
        ->and($warning->url)->toBe(url("/servers/{$this->server->id}"));

    // Climbs past 90%: the critical alert joins; repeated checks raise nothing more.
    health_check_held($this, ['disks' => ['/' => [95 * GIB, 5 * GIB, 100 * GIB]]]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.disk_usage'))->toHaveCount(2)
        ->and(health_alerts('servers.disk_usage')->pluck('severity')->all())->toContain(Severity::Critical)
        ->and(Condition::query()->where('key', 'like', 'servers.disk:%')->pluck('key')->every(fn ($key) => ! str_contains($key, '/')))->toBeTrue();

    // Hovering around the thresholds changes nothing (no flapping).
    foreach ([88, 91, 86, 89] as $percent) {
        ($this->online)(['disks' => ['/' => [$percent * GIB, (100 - $percent) * GIB, 100 * GIB]]]);
        dispatch_sync(new CheckServerHealth);
    }
    expect(health_alerts('servers.disk_usage', recovery: true))->toHaveCount(0);

    // 85%: 5 points below critical resolves it; 50%: the warning too.
    ($this->online)(['disks' => ['/' => [85 * GIB, 15 * GIB, 100 * GIB]]]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.disk_usage', recovery: true))->toHaveCount(1);

    // Freed: both resolve.
    ($this->online)(['disks' => ['/' => [50 * GIB, 50 * GIB, 100 * GIB]]]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.disk_usage', recovery: true))->toHaveCount(2)
        ->and(Condition::query()->where('key', 'like', 'servers.disk:%')->count())->toBe(0);
});

it('falls back to the root filesystem for agents without disks', function () {
    health_check_held($this, ['disk_used_bytes' => 82 * GIB]);

    expect(health_alerts('servers.disk_usage')->sole()->title)->toBe('Disk / on web-1 is 82% full');
});

it('skips offline agents and inactive servers', function () {
    ($this->online)(['disks' => ['/' => [95 * GIB, 5 * GIB, 100 * GIB]]]);
    $this->agent->forceFill(['status' => AgentStatus::Offline])->save();
    dispatch_sync(new CheckServerHealth);
    expect(Alert::query()->count())->toBe(0);

    ($this->online)(['disks' => ['/' => [95 * GIB, 5 * GIB, 100 * GIB]]]);
    $this->server->forceFill(['status' => ServerStatus::Provisioning])->save();
    dispatch_sync(new CheckServerHealth);
    expect(Alert::query()->count())->toBe(0);
});

it('alerts on memory only when every sample of ten minutes is above 90%', function () {
    ($this->online)();
    // Above for eight minutes only: the window is not covered.
    health_samples($this->agent, 8, fn () => ['memory_used_bytes' => (int) (7.5 * GIB)]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.memory_high'))->toHaveCount(0);

    AgentMetric::query()->delete();
    // Twelve minutes with one dip: no alert.
    health_samples($this->agent, 12, fn (int $s) => ['memory_used_bytes' => $s === 300 ? 6 * GIB : (int) (7.5 * GIB)]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.memory_high'))->toHaveCount(0);

    AgentMetric::query()->delete();
    health_samples($this->agent, 12, fn () => ['memory_used_bytes' => (int) (7.5 * GIB)]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.memory_high')->sole()->title)->toBe('Memory on web-1 above 90% for 10 minutes');

    // Just under the threshold: still raised (it resolves 5 points below, for 5 minutes).
    $this->travel(6)->minutes();
    health_samples($this->agent, 6, fn () => ['memory_used_bytes' => (int) (7.0 * GIB)]); // 87.5%
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.memory_high', recovery: true))->toHaveCount(0);

    // Back well below: resolved.
    $this->travel(6)->minutes();
    health_samples($this->agent, 6, fn () => ['memory_used_bytes' => 2 * GIB]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.memory_high', recovery: true))->toHaveCount(1);
});

it('alerts on CPU over 15 minutes and load over twice the CPUs', function () {
    ($this->online)();
    health_samples($this->agent, 14, fn () => ['cpu_percent' => 97.0, 'load1' => 4.5]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.cpu_high'))->toHaveCount(0)->and(health_alerts('servers.load_high'))->toHaveCount(0);

    AgentMetric::query()->delete();
    health_samples($this->agent, 16, fn () => ['cpu_percent' => 97.0, 'load1' => 4.5]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.cpu_high'))->toHaveCount(1)
        ->and(health_alerts('servers.load_high')->sole()->title)->toBe('Load on web-1 above 4 for 15 minutes');

    // At the limit: no longer above it, but not 10% below either: still raised.
    AgentMetric::query()->delete();
    health_samples($this->agent, 16, fn () => ['cpu_percent' => 97.0, 'load1' => 4.0]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.load_high', recovery: true))->toHaveCount(0);

    AgentMetric::query()->delete();
    health_samples($this->agent, 16, fn () => ['cpu_percent' => 97.0, 'load1' => 3.5]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.load_high', recovery: true))->toHaveCount(1);
});

it('alerts when the server needs a reboot', function () {
    ($this->online)(facts: ['reboot_required' => true]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.reboot_required')->sole()->title)->toBe('web-1 needs a reboot');

    ($this->online)(facts: ['reboot_required' => false]);
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.reboot_required', recovery: true))->toHaveCount(1);
});

it('alerts when the agent stays outdated for an hour, with the update as the suggested fix', function () {
    $dir = sys_get_temp_dir().'/falak-agent-bin-'.Str::random(8);
    mkdir($dir);
    file_put_contents("{$dir}/falak-agent-linux-amd64", 'agent v1.1.0');
    file_put_contents("{$dir}/falak-agent-linux-amd64.version", 'v1.1.0');
    config(['fleet.agent.binaries_path' => $dir, 'fleet.panel_url' => 'https://falak.example.com']);
    app()->forgetInstance(AgentBinaries::class);
    ($this->online)(facts: ['agent_version' => 'v1.0.0', 'agent_sha256' => str_repeat('a', 64)]);
    $this->agent->forceFill(['agent_version' => 'v1.0.0'])->save();

    dispatch_sync(new CheckServerHealth);
    $this->travel(30)->minutes();
    ($this->online)();
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.agent_outdated'))->toHaveCount(0);

    $this->travel(31)->minutes();
    ($this->online)();
    dispatch_sync(new CheckServerHealth);
    $alert = health_alerts('servers.agent_outdated')->sole();
    expect($alert->title)->toBe('An agent is outdated')
        ->and($alert->action)->toBe('Update agent')
        ->and($alert->url)->toBe(url('/servers'))
        ->and($alert->body)->toContain('web-1 (v1.0.0)');

    // A second outdated server joins the same alert: no second one.
    $second = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-2']);
    $agent2 = fleet_enroll($this->organization->id, $second->id, ['agent_version' => 'v1.0.0', 'agent_sha256' => str_repeat('a', 64)])['agent'];
    $agent2->forceFill(['status' => AgentStatus::Online, 'last_heartbeat_at' => now()])->save();
    dispatch_sync(new CheckServerHealth);
    expect(health_alerts('servers.agent_outdated'))->toHaveCount(1);
});

it('forecasts a disk filling within 48 hours from six hours of samples', function () {
    // 60 GiB used, growing 1 GiB an hour, 100 GiB disk: full in about 40 hours.
    ($this->online)(['disks' => ['/' => [60 * GIB, 40 * GIB, 100 * GIB]]]);
    health_samples($this->agent, 360, fn (int $s) => ['disks' => ['/' => [(int) (60 * GIB - $s / 3600 * GIB), 0, 100 * GIB]]], 300);

    dispatch_sync(new CheckServerHealth); // no forecast on the per-minute run
    expect(health_alerts('servers.disk_forecast'))->toHaveCount(0);

    dispatch_sync(new CheckServerHealth(forecast: true));
    $alert = health_alerts('servers.disk_forecast')->sole();
    expect($alert->title)->toBe('Disk / on web-1 will be full in about 1.7 days')
        ->and($alert->context['hours_until_full'])->toEqualWithDelta(40.0, 0.2);
});

it('never forecasts from flat, falling, noisy or short data', function (Closure $used, int $minutes) {
    ($this->online)(['disks' => ['/' => [60 * GIB, 40 * GIB, 100 * GIB]]]);
    health_samples($this->agent, $minutes, fn (int $s) => ['disks' => ['/' => [(int) $used($s), 0, 100 * GIB]]], 300);

    dispatch_sync(new CheckServerHealth(forecast: true));

    expect(health_alerts('servers.disk_forecast'))->toHaveCount(0);
})->with([
    'flat' => [fn (int $s) => 60 * GIB, 360],
    'falling' => [fn (int $s) => 60 * GIB + $s / 3600 * GIB, 360],
    'noisy' => [fn (int $s) => 60 * GIB + (($s / 300) % 2 === 0 ? 3 : -3) * GIB + ($s % 7) * 1024 ** 2, 360],
    'two hours only' => [fn (int $s) => 60 * GIB - $s / 3600 * 10 * GIB, 120],
]);

it('resolves a forecast once the disk stops filling', function () {
    ($this->online)(['disks' => ['/' => [60 * GIB, 40 * GIB, 100 * GIB]]]);
    health_samples($this->agent, 360, fn (int $s) => ['disks' => ['/' => [(int) (60 * GIB - $s / 3600 * GIB), 0, 100 * GIB]]], 300);
    dispatch_sync(new CheckServerHealth(forecast: true));

    AgentMetric::query()->delete();
    health_samples($this->agent, 360, fn () => ['disks' => ['/' => [60 * GIB, 0, 100 * GIB]]], 300);
    dispatch_sync(new CheckServerHealth(forecast: true));

    expect(health_alerts('servers.disk_forecast', recovery: true))->toHaveCount(1);
});

it('keeps servers of other organizations out of an organization\'s alerts', function () {
    [, $other] = memberOf();
    $foreign = Server::factory()->create(['organization_id' => $other->id, 'name' => 'theirs']);
    $agent = fleet_enroll($other->id, $foreign->id)['agent'];
    $agent->forceFill(['status' => AgentStatus::Online, 'last_heartbeat_at' => now(), 'metrics' => ['disks' => ['/' => [95 * GIB, 5 * GIB, 100 * GIB]]]])->save();
    ($this->online)();

    dispatch_sync(new CheckServerHealth);
    $this->travel(5)->minutes();
    $agent->forceFill(['last_heartbeat_at' => now()])->save();
    ($this->online)();
    dispatch_sync(new CheckServerHealth);

    expect(Alert::query()->where('organization_id', $this->organization->id)->count())->toBe(0)
        ->and(Alert::query()->where('organization_id', $other->id)->where('type', 'servers.disk_usage')->count())->toBe(2);
});

it('keys long mount paths by a hash and keeps the path in the alert', function () {
    $mount = '/mnt/'.str_repeat('very-long-name/', 12);
    health_check_held($this, ['disks' => [$mount => [95 * GIB, 5 * GIB, 100 * GIB]]]);

    expect(Condition::query()->pluck('key')->every(fn ($key) => strlen($key) < 80))->toBeTrue()
        ->and(health_alerts('servers.disk_usage')->first()->context['mount'])->toBe($mount);
});
