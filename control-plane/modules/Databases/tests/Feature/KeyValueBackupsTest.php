<?php

use Falak\Databases\Application\Actions\RunBackupSchedule;
use Falak\Databases\Application\EngineInventory;
use Falak\Databases\Application\Jobs\RunDueBackups;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Events\BackupFailed;
use Falak\Databases\Events\BackupSucceeded;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Fleet\Application\PayloadCompatibility;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\Role;
use Falak\Servers\Contracts\ServerType;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

/*
 * Redis / Valkey backups and restores (v0.9.0, phase 3): db.backup / db.restore with engine redis | valkey, RDB
 * snapshots, agents with db.redis.backup only.
 */

const KVB_FEATURES = ['db.redis', 'db.redis.network', 'db.redis.backup'];

function kvb_engine(object $test, string $cache = 'redis', array $features = KVB_FEATURES): DatabaseServer
{
    $server = databases_server($test->organization, 'postgresql', ServerType::App, ['stack' => ['database' => 'postgresql', 'cache' => $cache]]);
    Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $test->organization->id, 'facts' => ['features' => $features, 'memory_bytes' => 2 * 1024 ** 3]]);
    app(EngineInventory::class)->sync($server->id);

    return DatabaseServer::query()->where('server_id', $server->id)->where('engine', $cache)->firstOrFail();
}

function kvb_instance(object $test, DatabaseServer $engine, string $name = 'cache'): Database
{
    $data = app(DatabaseProvisioner::class)->create($test->organization->id, $engine->server_id, $engine->engine->value, $name);
    $test->agents->succeed($test->agents->last('db.redis.apply')['handle'], ['changed' => true, 'restarted' => true, 'port' => (int) $data->port]);

    return Database::query()->findOrFail($data->id);
}

function kvb_result(string $content = 'REDIS0011'): array
{
    return ['size_bytes' => strlen($content) * 100, 'sha256' => hash('sha256', $content), 'location' => 's3://falak-backups/x', 'duration_ms' => 1200, 'rdb' => 'REDIS0011'];
}

beforeEach(function () {
    databases_fake_dns();
    Carbon::setTestNow('2026-10-06 02:59:30');
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->engine = kvb_engine($this);
    $this->instance = kvb_instance($this, $this->engine);
    $this->provider = databases_provider($this->organization);
});

afterEach(fn () => Carbon::setTestNow());

it('backs up an instance: db.backup with engine redis, the instance name and an .rdb.gz key', function () {
    Event::fake([BackupSucceeded::class]);
    $this->post("/databases/databases/{$this->instance->id}/backups", ['storage_provider_id' => $this->provider->id])->assertSessionHasNoErrors();

    $backup = Backup::query()->firstOrFail();
    $command = $this->agents->last('db.backup');
    expect(databases_schema_errors($command))->toBe([])
        ->and($command['payload'])->toMatchArray(['engine' => 'redis', 'database' => 'cache', 'compression' => 'gzip'])
        ->and($command['payload']['destination']['kind'])->toBe('presigned_url')
        ->and(json_encode($command['payload']))->not->toContain('super-secret-access-key-value')
        ->and($backup->engine->value)->toBe('redis')
        ->and($backup->started_at?->toIso8601String())->toBe('2026-10-06T02:59:30+00:00')
        ->and($backup->object_key)->toMatch('#^acme/'.preg_quote(Str::slug($this->engine->server_name), '#').'-[a-z0-9]{6}/cache/2026/10/20261006T025930Z-'.$backup->id.'\.rdb\.gz$#');

    $this->agents->succeed($command['handle'], kvb_result());
    expect($backup->refresh())->status->toBe(BackupStatus::Succeeded)->and($backup->isRestorable())->toBeTrue()
        ->and($this->getJson("/databases/databases/{$this->instance->id}")->json('data.backups.0.started_at'))->toBe('2026-10-06T02:59:30+00:00');
    Event::assertDispatched(BackupSucceeded::class, fn ($e) => $e->databaseName === 'cache');

    // Uncompressed: the plain RDB file.
    $this->post("/databases/databases/{$this->instance->id}/backups", ['storage_provider_id' => $this->provider->id, 'compression' => 'none']);
    expect(Backup::query()->latest('id')->first()->object_key)->toEndWith('.rdb');
});

it('refuses backups, schedules and restores on servers whose agent lacks db.redis.backup', function () {
    Event::fake([BackupFailed::class]);
    $old = kvb_engine($this, 'valkey', ['db.redis', 'db.redis.network']);
    $instance = kvb_instance($this, $old, 'sessions');
    $message = "Update the agent on {$old->server_name} first: backups and restores of Valkey instances need a newer agent (feature db.redis.backup).";

    $this->post("/databases/databases/{$instance->id}/backups", ['storage_provider_id' => $this->provider->id])
        ->assertSessionHasErrors(['database' => $message]);
    $this->post("/databases/servers/{$old->id}/schedules", ['name' => 'Nightly', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$instance->id], 'cron' => '0 3 * * *'])
        ->assertSessionHasErrors(['database_ids' => $message]);

    // A schedule saved before a downgrade: its runs fail with the reason, nothing is sent.
    $schedule = BackupSchedule::query()->create(['organization_id' => $this->organization->id, 'database_server_id' => $old->id, 'name' => 'Nightly', 'storage_provider_id' => $this->provider->id,
        'cron' => '0 3 * * *', 'compression' => 'gzip', 'enabled' => true, 'next_run_at' => now()->addSeconds(30)]);
    $schedule->databases()->sync([$instance->id]);
    Carbon::setTestNow('2026-10-06 03:00:10');
    dispatch_sync(new RunDueBackups);
    expect(Backup::query()->firstOrFail())->status->toBe(BackupStatus::Failed)->error->toBe($message)->trigger->toBe('scheduled');
    Event::assertDispatched(BackupFailed::class);

    // A restore into it.
    $this->post("/databases/databases/{$this->instance->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], kvb_result());
    $backup = Backup::query()->where('status', 'succeeded')->firstOrFail();
    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $old->id, 'database' => 'sessions', 'confirm' => 'sessions'])
        ->assertSessionHasErrors(['database_server_id' => $message]);
    $this->agents->assertNothingDispatched('db.restore');
    expect(count($this->agents->dispatched('db.backup')))->toBe(1);
});

it('runs schedules of instances and prunes by retention like SQL backups', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $this->post("/databases/servers/{$this->engine->id}/schedules", [
        'name' => 'Nightly', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->instance->id], 'cron' => '0 3 * * *', 'retention_count' => 2,
    ])->assertSessionHasNoErrors();
    $schedule = BackupSchedule::query()->firstOrFail();
    expect($schedule->next_run_at->toIso8601String())->toBe('2026-10-06T03:00:00+00:00');

    foreach (['2026-10-06 03:00:10', '2026-10-07 03:00:10', '2026-10-08 03:00:10'] as $i => $when) {
        Carbon::setTestNow($when);
        (new RunDueBackups)->handle(app(RunBackupSchedule::class), app(CurrentOrganization::class));
        $command = $this->agents->last('db.backup');
        expect(databases_schema_errors($command))->toBe([])->and($command['payload']['engine'])->toBe('redis');
        $this->agents->succeed($command['handle'], kvb_result("REDIS0011-{$i}"));
    }

    $backups = Backup::query()->orderBy('created_at')->orderBy('id')->get();
    expect($backups->pluck('trigger')->unique()->all())->toBe(['scheduled'])
        ->and($backups->pluck('status')->all())->toBe([BackupStatus::Pruned, BackupStatus::Succeeded, BackupStatus::Succeeded]);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), $backups[0]->object_key) && str_ends_with($r->url(), '.rdb.gz'));
});

it('restores a snapshot into an existing instance, Redis into Valkey too, never creating one', function () {
    $this->post("/databases/databases/{$this->instance->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], kvb_result());
    $backup = Backup::query()->firstOrFail();
    $valkey = kvb_engine($this, 'valkey');
    kvb_instance($this, $valkey, 'sessions');

    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $valkey->id, 'database' => 'sessions', 'confirm' => 'sessions'])->assertSessionHasNoErrors();

    $command = $this->agents->last('db.restore');
    $restore = Restore::query()->firstOrFail();
    expect(databases_schema_errors($command))->toBe([])
        ->and($command['payload'])->toMatchArray(['engine' => 'valkey', 'database' => 'sessions', 'compression' => 'gzip', 'sha256' => hash('sha256', 'REDIS0011')])
        ->and($command['payload']['source']['url'])->toStartWith("https://falak-backups.s3.eu-central-1.amazonaws.com/{$backup->object_key}?")
        ->and($command['payload'])->not->toHaveKey('password');

    $before = Database::query()->count();
    $this->agents->succeed($command['handle'], ['bytes' => 900, 'duration_ms' => 3000, 'rdb' => 'REDIS0011', 'moved_aside' => ['dump.rdb.falak-20261006T030000Z']]);
    expect($restore->refresh())->status->toBe(RestoreStatus::Succeeded)->bytes->toBe(900)
        ->and(Database::query()->count())->toBe($before);

    // The agent's refusal (a Redis 8 snapshot into Valkey) is recorded as the restore's error.
    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $valkey->id, 'database' => 'sessions', 'confirm' => 'sessions'])->assertSessionHasNoErrors();
    $this->agents->fail($this->agents->last('db.restore')['handle'], 'this snapshot (REDIS0012, RDB version 12) comes from Redis 7.4 or newer');
    expect(Restore::query()->latest('id')->first())->status->toBe(RestoreStatus::Failed)->error->toContain('Redis 7.4 or newer')
        ->and(Database::query()->count())->toBe($before);
});

it('only restores snapshots into existing, active Redis / Valkey instances, and SQL dumps never into them', function () {
    $this->post("/databases/databases/{$this->instance->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], kvb_result());
    $snapshot = Backup::query()->firstOrFail();
    $sql = databases_engine($this->organization, 'mysql', ServerType::Database);
    $shop = databases_active_db($sql, 'shop');

    $restore = fn (Backup $backup, DatabaseServer $target, string $name) => $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $target->id, 'database' => $name, 'confirm' => $name]);

    $restore($snapshot, $sql, 'shop')->assertSessionHasErrors(['database_server_id' => 'A Redis snapshot can only be restored into a Redis or Valkey instance, not into MySQL.']);
    $restore($snapshot, $this->engine, 'missing')->assertSessionHasErrors(['database' => "{$this->engine->server_name} has no Redis instance named \"missing\"."]);
    $restore($snapshot, $this->engine, 'Bad.Name')->assertSessionHasErrors('database');
    $pending = $this->engine->databases()->create(['organization_id' => $this->organization->id, 'server_id' => $this->engine->server_id, 'name' => 'wip', 'port' => 6399, 'status' => 'pending']);
    $restore($snapshot, $this->engine, 'wip')->assertSessionHasErrors(['database' => 'Instance "wip" is pending.']);

    $this->post("/databases/databases/{$shop->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], kvb_result('dump'));
    $dump = Backup::query()->where('engine', 'mysql')->firstOrFail();
    $restore($dump, $this->engine, 'cache')->assertSessionHasErrors(['database_server_id' => 'A MySQL dump cannot be restored into a Redis instance.']);

    $this->agents->assertNothingDispatched('db.restore');
    expect($pending->exists)->toBeTrue();
});

it('shows the Backups data in the instance panel with key-value restore targets and their instances', function () {
    $valkey = kvb_engine($this, 'valkey');
    kvb_instance($this, $valkey, 'sessions');
    databases_engine($this->organization, 'mysql', ServerType::Database);

    $targets = $this->getJson("/databases/databases/{$this->instance->id}")->assertOk()->json('data.restore_targets');
    expect(collect($targets)->pluck('engine')->sort()->values()->all())->toBe(['redis', 'valkey'])
        ->and(collect($targets)->firstWhere('engine', 'valkey')['instances'])->toBe(['sessions'])
        ->and(collect($targets)->firstWhere('engine', 'redis')['instances'])->toBe(['cache']);

    $this->get("/databases/servers/{$this->engine->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Databases/Show', false)->has('restoreTargets', 2));
});

it('gives a short-lived download link to people who may restore, audited', function () {
    $this->post("/databases/databases/{$this->instance->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $backup = Backup::query()->firstOrFail();

    $this->getJson("/databases/backups/{$backup->id}/download")->assertUnprocessable();

    $this->agents->succeed($this->agents->last('db.backup')['handle'], kvb_result());
    $url = $this->getJson("/databases/backups/{$backup->id}/download")->assertOk()->json('url');
    expect($url)->toStartWith("https://falak-backups.s3.eu-central-1.amazonaws.com/{$backup->object_key}?")->toContain('X-Amz-Expires=300');
    $this->get("/databases/backups/{$backup->id}/download")->assertRedirectContains($backup->object_key);
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'databases.backup_downloaded', 'subject_id' => $backup->id]);

    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer)->get("/databases/backups/{$backup->id}/download")->assertForbidden();
    [$outsider] = memberOf();
    $this->actingAs($outsider)->get("/databases/backups/{$backup->id}/download")->assertNotFound();
});

it('records the snapshot size before compression and sends it with restores to agents with db.redis.restore_checks', function () {
    $this->post("/databases/databases/{$this->instance->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], [...kvb_result(), 'uncompressed_bytes' => 5_368_709_120]);
    $backup = Backup::query()->firstOrFail();
    expect($backup->uncompressed_bytes)->toBe(5_368_709_120);

    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $this->engine->id, 'database' => 'cache', 'confirm' => 'cache'])->assertSessionHasNoErrors();
    $command = $this->agents->last('db.restore');
    expect(databases_schema_errors($command))->toBe([])
        ->and($command['payload']['uncompressed_bytes'])->toBe(5_368_709_120);

    // Agents without the feature (rc.1) decode strictly: the field is stripped.
    $payload = json_decode(json_encode($command['payload']));
    expect((array) PayloadCompatibility::adapt('db.restore', clone $payload, KVB_FEATURES))->not->toHaveKey('uncompressed_bytes')
        ->and(PayloadCompatibility::adapt('db.restore', clone $payload, [...KVB_FEATURES, 'db.redis.restore_checks'])->uncompressed_bytes)->toBe(5_368_709_120);

    // Backups of older agents carry no size; SQL restores never send one.
    $this->agents->fail($command['handle'], 'x');
    $backup->forceFill(['uncompressed_bytes' => null])->save();
    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $this->engine->id, 'database' => 'cache', 'confirm' => 'cache'])->assertSessionHasNoErrors();
    expect($this->agents->last('db.restore')['payload'])->not->toHaveKey('uncompressed_bytes')
        ->and(CommandPayloads::restore(Engine::MySql, 'shop', Compression::Gzip, 'https://x', null, 1000))->not->toHaveKey('uncompressed_bytes');
});

it('keeps a restore\'s warnings, shows them and re-applies the instance so the agent converges', function () {
    $this->post("/databases/databases/{$this->instance->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], kvb_result());
    $backup = Backup::query()->firstOrFail();
    $applies = count($this->agents->dispatched('db.redis.apply'));

    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $this->engine->id, 'database' => 'cache', 'confirm' => 'cache'])->assertSessionHasNoErrors();
    $warning = 'redis-server@falak-cache.service runs with AOF on, but its config file could not be put back (disk full)';
    $this->agents->succeed($this->agents->last('db.restore')['handle'], ['bytes' => 900, 'duration_ms' => 3000, 'rdb' => 'REDIS0011', 'warnings' => [$warning, '', 42]]);

    expect(Restore::query()->firstOrFail())->status->toBe(RestoreStatus::Succeeded)->warnings->toBe([$warning])->error->toBeNull()
        ->and(count($this->agents->dispatched('db.redis.apply')))->toBe($applies + 1)
        ->and($this->agents->last('db.redis.apply')['payload']['name'])->toBe('cache')
        ->and($this->getJson("/databases/databases/{$this->instance->id}")->assertOk()->json('data.restores.0.warnings'))->toBe([$warning]);

    // Without warnings nothing more is sent.
    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $this->engine->id, 'database' => 'cache', 'confirm' => 'cache'])->assertSessionHasNoErrors();
    $this->agents->succeed($this->agents->last('db.restore')['handle'], ['bytes' => 900, 'duration_ms' => 3000, 'rdb' => 'REDIS0011']);
    expect(Restore::query()->latest('id')->first()->warnings)->toBeNull()
        ->and(count($this->agents->dispatched('db.redis.apply')))->toBe($applies + 1);
});
