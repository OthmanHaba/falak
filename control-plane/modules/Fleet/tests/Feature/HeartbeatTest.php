<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Falak\Fleet\Application\Jobs\SweepFleet;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Domain\Models\AgentMetric;
use Falak\Fleet\Events\AgentCameOnline;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Fleet\Events\AgentWentOffline;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [, $organization] = memberOf();
    $this->serverId = (string) Str::ulid();
    $this->enrolled = fleet_enroll($organization->id, $this->serverId);
    $this->agent = $this->enrolled['agent'];
    $this->headers = fleet_mtls($this->enrolled['fingerprint']);
});

it('records heartbeat metrics and returns 204', function () {
    $heartbeat = fleet_heartbeat(['load' => [1.5, 1.0, 0.5], 'running_commands' => []]);
    expect(fleet_schema_errors('heartbeat.schema.json', $heartbeat))->toBe([]);

    $this->postJson('/agent/v1/heartbeat', $heartbeat, $this->headers)->assertNoContent();

    $info = app(AgentDirectory::class)->forServer($this->serverId);
    expect($info->metrics['load'])->toEqual([1.5, 1.0, 0.5])
        ->and($info->metrics['memory_used_bytes'])->toBe(2 * 1024 ** 3)
        ->and($info->isOnline())->toBeTrue()
        ->and(AgentMetric::query()->where('agent_id', $this->agent->id)->count())->toBe(1)
        ->and(app(AgentDirectory::class)->metrics($this->serverId, now()->subHour()))->toHaveCount(1);
});

it('updates facts and announces them only when sent', function () {
    Event::fake([AgentFactsReported::class]);

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), $this->headers)->assertNoContent();
    Event::assertNotDispatched(AgentFactsReported::class);

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(['facts' => fleet_facts(['hostname' => 'web-renamed', 'agent_version' => '1.1.0'])]), $this->headers)->assertNoContent();

    Event::assertDispatched(AgentFactsReported::class, fn (AgentFactsReported $e) => $e->facts['hostname'] === 'web-renamed' && $e->serverId === $this->serverId);
    expect($this->agent->refresh()->hostname)->toBe('web-renamed')
        ->and($this->agent->agent_version)->toBe('1.1.0');
});

it('validates heartbeats against the schema', function () {
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(['load' => [1, 2]]), $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors('load');

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(['facts' => ['hostname' => 'x']]), $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors('facts');
});

it('marks silent agents offline and back online on the next heartbeat', function () {
    Event::fake([AgentWentOffline::class, AgentCameOnline::class]);

    $this->travel(30)->seconds();
    SweepFleet::dispatchSync();
    expect($this->agent->refresh()->status)->toBe(AgentStatus::Online);

    $this->travel(61)->seconds();
    SweepFleet::dispatchSync();
    expect($this->agent->refresh()->status)->toBe(AgentStatus::Offline);
    Event::assertDispatched(AgentWentOffline::class, fn (AgentWentOffline $e) => $e->serverId === $this->serverId);

    SweepFleet::dispatchSync();
    Event::assertDispatchedTimes(AgentWentOffline::class, 1);

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), $this->headers)->assertNoContent();
    expect($this->agent->refresh()->status)->toBe(AgentStatus::Online);
    Event::assertDispatched(AgentCameOnline::class, fn (AgentCameOnline $e) => $e->agentId === $this->agent->id);
});

it('prunes metrics older than the retention window', function () {
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), $this->headers)->assertNoContent();

    $this->travel(25)->hours();
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(['at' => now()->toIso8601ZuluString()]), $this->headers)->assertNoContent();
    SweepFleet::dispatchSync();

    expect(AgentMetric::query()->count())->toBe(1);
});

it('announces a changed agent version with the features it reports', function () {
    Event::fake([AgentVersionChanged::class]);

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(['facts' => fleet_facts()]), $this->headers)->assertNoContent();
    Event::assertNotDispatched(AgentVersionChanged::class);

    $facts = fleet_facts(['agent_version' => '1.1.0', 'features' => ['edge.access_log']]);
    expect(fleet_schema_errors('facts.schema.json', $facts))->toBe([]);
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(['facts' => $facts]), $this->headers)->assertNoContent();

    Event::assertDispatched(AgentVersionChanged::class, fn (AgentVersionChanged $e) => $e->serverId === $this->serverId
        && $e->previousVersion === '1.0.0' && $e->version === '1.1.0' && $e->features === ['edge.access_log']);
    expect(app(AgentDirectory::class)->forServer($this->serverId)->supports('edge.access_log'))->toBeTrue()
        ->and(app(AgentDirectory::class)->forServer($this->serverId)->supports('telemetry.log_kind'))->toBeFalse();
});
