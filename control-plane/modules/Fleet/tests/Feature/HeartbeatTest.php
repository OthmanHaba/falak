<?php

use Falak\Fleet\Application\Jobs\SweepFleet;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Domain\Models\AgentMetric;
use Falak\Fleet\Events\AgentCameOnline;
use Falak\Fleet\Events\AgentDatabasesReported;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Fleet\Events\AgentSecretsMissing;
use Falak\Fleet\Events\AgentServiceEventsReported;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Fleet\Events\AgentWentOffline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

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

it('announces sites whose secrets the server lost (missing_secrets)', function () {
    Event::fake([AgentSecretsMissing::class]);

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), $this->headers)->assertNoContent();
    Event::assertNotDispatched(AgentSecretsMissing::class);

    $heartbeat = fleet_heartbeat(['missing_secrets' => ['shop', 'api', 'shop']]);
    expect(fleet_schema_errors('heartbeat.schema.json', $heartbeat))->toBe([])
        ->and(fleet_schema_errors('heartbeat.schema.json', fleet_heartbeat(['missing_secrets' => ['../etc']])))->not->toBe([]);

    $this->postJson('/agent/v1/heartbeat', $heartbeat, $this->headers)->assertNoContent();
    Event::assertDispatched(AgentSecretsMissing::class, fn (AgentSecretsMissing $e) => $e->serverId === $this->serverId && $e->sites === ['shop', 'api']);
});

it('reports the server\'s database containers (databases)', function () {
    Event::fake([AgentDatabasesReported::class]);

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), $this->headers)->assertNoContent();
    Event::assertNotDispatched(AgentDatabasesReported::class);

    $id = strtolower((string) Str::ulid());
    $heartbeat = fleet_heartbeat(['databases' => [
        ['id' => $id, 'state' => 'running', 'health' => 'healthy', 'secrets_missing' => false],
        ['id' => strtolower((string) Str::ulid()), 'state' => 'created', 'health' => 'none', 'secrets_missing' => true,
            'pitr' => ['spool_bytes' => 4096, 'volume_bytes' => 10737418240, 'pending' => 2, 'oldest_pending_at' => '2026-10-09T12:00:00Z']],
    ]]);
    expect(fleet_schema_errors('heartbeat.schema.json', $heartbeat))->toBe([])
        ->and(fleet_schema_errors('heartbeat.schema.json', fleet_heartbeat(['databases' => [['id' => '../x', 'state' => 'running', 'health' => 'healthy', 'secrets_missing' => false]]])))->not->toBe([]);

    $this->postJson('/agent/v1/heartbeat', $heartbeat, $this->headers)->assertNoContent();
    Event::assertDispatched(AgentDatabasesReported::class, fn (AgentDatabasesReported $e) => $e->serverId === $this->serverId
        && count($e->instances) === 2
        && $e->instances[0] === ['id' => $id, 'state' => 'running', 'health' => 'healthy', 'secrets_missing' => false, 'pitr' => null]
        && $e->instances[1]['secrets_missing'] === true
        && $e->instances[1]['pitr']['pending'] === 2);

    // An empty list still reports (the server runs none any more).
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(['databases' => []]), $this->headers)->assertNoContent();
    Event::assertDispatched(AgentDatabasesReported::class, fn (AgentDatabasesReported $e) => $e->instances === []);
});

it('reports OOM kills and restarts (service_events)', function () {
    Event::fake([AgentServiceEventsReported::class]);

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), $this->headers)->assertNoContent();
    Event::assertNotDispatched(AgentServiceEventsReported::class);

    $heartbeat = fleet_heartbeat(['service_events' => [
        ['kind' => 'oom_kill', 'source' => 'container', 'name' => 'falak-shop-blue', 'site' => 'shop', 'count' => 1, 'at' => now()->toIso8601ZuluString()],
        ['kind' => 'restart', 'source' => 'slice', 'name' => 'worker_01j9z8y7x6w5v4t3s2r1q0p9na', 'count' => 3, 'at' => now()->toIso8601ZuluString()],
        ['kind' => 'oom_kill', 'source' => 'container', 'name' => 'falak-db-x', 'instance' => '01HZYINST00000000000000001', 'count' => 2, 'at' => now()->toIso8601ZuluString()],
    ]]);
    expect(fleet_schema_errors('heartbeat.schema.json', $heartbeat))->toBe([])
        ->and(fleet_schema_errors('heartbeat.schema.json', fleet_heartbeat(['service_events' => [['kind' => 'panic', 'source' => 'container', 'name' => 'x', 'count' => 1, 'at' => now()->toIso8601ZuluString()]]])))->not->toBe([]);

    $this->postJson('/agent/v1/heartbeat', $heartbeat, $this->headers)->assertNoContent();
    Event::assertDispatched(AgentServiceEventsReported::class, fn (AgentServiceEventsReported $e) => $e->serverId === $this->serverId
        && count($e->events) === 3
        && $e->events[0]['site'] === 'shop' && $e->events[0]['project'] === null
        && $e->events[1]['count'] === 3
        && $e->events[2]['instance'] === '01hzyinst00000000000000001');
});
