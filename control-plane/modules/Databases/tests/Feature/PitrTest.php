<?php

use Falak\Databases\Application\Actions\PrunePitr;
use Falak\Databases\Application\Actions\TakePitrBase;
use Falak\Databases\Application\Jobs\MaintainPitr;
use Falak\Databases\Application\PitrTimeline;
use Falak\Databases\Contracts\DatabaseRecovery;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\PitrGap;
use Falak\Databases\Domain\Models\PitrSegment;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Events\DatabaseCreated;
use Falak\Databases\Events\PitrAlert;
use Falak\Fleet\Domain\Models\Certificate;
use Falak\Fleet\Events\AgentDatabasesReported;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\Organization;
use Falak\Kernel\Security\BackupKeys;
use Falak\Kernel\Security\DecryptionFailed;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

const PITR_RECIPIENT = 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p';

function pitr_identity(): string
{
    return 'AGE-SECRET-KEY-1'.str_repeat('Q', 58);
}

beforeEach(function () {
    databases_fake_dns();
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    Carbon::setTestNow('2026-10-09 12:00:00');
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = databases_server($this->organization);
    [$this->db, $this->appUser, $this->engine] = databases_service($this->organization, 'postgresql', 'shop', $this->server);
    $this->provider = databases_provider($this->organization);
});

afterEach(fn () => Carbon::setTestNow());

/** A fake bucket (Http::fake): objects by key; HEAD gives the size, GET the content, DELETE removes. */
function pitr_bucket(object $test): void
{
    $test->bucket = new ArrayObject;
    $test->deleted = new ArrayObject;
    $bucket = $test->bucket;
    $deleted = $test->deleted;
    Http::fake(function ($request) use ($bucket, $deleted) {
        $key = rawurldecode(ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/'));

        return match ($request->method()) {
            'HEAD' => isset($bucket[$key]) ? Http::response('', 200, ['Content-Length' => (string) strlen($bucket[$key])]) : Http::response('', 404),
            'GET' => isset($bucket[$key]) ? Http::response($bucket[$key]) : Http::response('', 404),
            'DELETE' => (function () use ($bucket, $deleted, $key) {
                $deleted[] = $key;
                unset($bucket[$key]);

                return Http::response('', 204);
            })(),
            default => Http::response('', 200),
        };
    });
}

/** An enrolled agent whose certificate is valid on the frozen clock (it is issued at the real time, which may be later). */
function pitr_enroll(string $organizationId, string $serverId): array
{
    $enrolled = fleet_enroll($organizationId, $serverId);
    Certificate::query()->where('fingerprint', $enrolled['fingerprint'])->update(['not_before' => now()->subHour()]);

    return $enrolled;
}

/** PITR on (without going through the agent), with an agent enrolled for the instance's server. */
function pitr_on(object $test, array $attributes = []): DatabaseInstance
{
    $test->engine->forceFill(['pitr_enabled' => true, 'pitr_storage_provider_id' => $test->provider->id, ...$attributes])->save();
    $test->enrolled ??= pitr_enroll($test->organization->id, $test->server->id);
    if (! isset($test->bucket)) {
        pitr_bucket($test);
    }
    $test->headers ??= fleet_mtls($test->enrolled['fingerprint']);

    return $test->engine;
}

/** The agent asks for URLs, uploads and acknowledges one segment. */
function pitr_ship(object $test, string $name, string $endTime, ?DatabaseInstance $instance = null, string $kind = 'wal'): PitrSegment
{
    $instance ??= $test->engine;
    $sha = hash('sha256', $name.$endTime);
    $slot = $test->postJson('/agent/v1/requests/pitr.upload_urls', ['instance' => $instance->id, 'kind' => $kind, 'segments' => [['name' => $name, 'bytes' => 16, 'sha256' => $sha]]], $test->headers)
        ->assertOk()->json('segments.0');
    // What the agent PUT to the presigned URL.
    $stored = "FKB1 {$name} {$endTime}";
    $test->bucket[PitrSegment::query()->findOrFail($slot['id'])->object_key] = $stored;
    $test->postJson('/agent/v1/requests/pitr.shipped', ['instance' => $instance->id, 'kind' => $kind, 'segments' => [[
        'id' => $slot['id'], 'name' => $name, 'size_bytes' => strlen($stored), 'sha256' => hash('sha256', $stored), 'plaintext_bytes' => 16, 'plaintext_sha256' => $sha, 'end_time' => $endTime,
    ]]], $test->headers)->assertOk()->assertJson(['acknowledged' => [$slot['id']]]);

    return PitrSegment::query()->findOrFail($slot['id']);
}

/** A succeeded base of the instance, started and finished at the given times. */
function pitr_base(object $test, string $started, string $finished, ?string $logStart = '000000010000000000000003', string $mode = 'cp'): Backup
{
    $backup = new Backup;
    $backup->id = strtolower((string) Str::ulid());
    $backup->forceFill([
        'organization_id' => $test->organization->id, 'database_instance_id' => $test->engine->id, 'server_id' => $test->server->id, 'server_name' => $test->server->name,
        'instance_name' => $test->engine->name, 'database_name' => $test->engine->name, 'engine' => $test->engine->engine, 'engine_version' => $test->engine->version,
        'storage_provider_id' => $test->provider->id, 'object_key' => "acme/pitr/base/{$backup->id}.tar.zst.fkb", 'compression' => Compression::Zstd,
        'encryption_mode' => $mode, 'wrapped_key' => $mode === 'cp' ? app(BackupKeys::class)->generate($test->organization->id, $backup->id)[1] : null,
        'age_recipient' => $mode === 'customer' ? PITR_RECIPIENT : null, 'cipher' => 'aes-256-gcm',
        'type' => Backup::BASE, 'pitr_epoch' => $test->engine->pitr_epoch, 'trigger' => 'pitr', 'status' => BackupStatus::Succeeded, 'size_bytes' => 1000, 'uncompressed_bytes' => 4000,
        'sha256' => str_repeat('a', 64), 'plaintext_sha256' => str_repeat('b', 64), 'log_start' => $logStart,
        'base_started_at' => Carbon::parse($started), 'base_finished_at' => Carbon::parse($finished), 'created_at' => Carbon::parse($finished),
    ])->save();

    return $backup;
}

it('turns PITR on with a storage provider and Falak-held keys, ships the spool and takes a base at once', function () {
    $this->put("/databases/instances/{$this->engine->id}/pitr", ['enabled' => true])->assertSessionHasErrors('storage_provider_id');
    $this->put("/databases/instances/{$this->engine->id}/pitr", ['enabled' => true, 'storage_provider_id' => $this->provider->id, 'encryption_mode' => 'customer'])
        ->assertSessionHasErrors('age_recipient');
    $foreign = databases_provider(Organization::factory()->create());
    $this->put("/databases/instances/{$this->engine->id}/pitr", ['enabled' => true, 'storage_provider_id' => $foreign->id])->assertSessionHasErrors('storage_provider_id');

    $this->put("/databases/instances/{$this->engine->id}/pitr", ['enabled' => true, 'storage_provider_id' => $this->provider->id, 'window_days' => 3, 'base_interval_days' => 1])
        ->assertSessionHasNoErrors();

    $instance = $this->engine->refresh();
    expect($instance->pitr_enabled)->toBeTrue()->and($instance->pitr_encryption_mode)->toBe('cp')->and($instance->pitr_window_days)->toBe(3);
    $update = $this->agents->last('db.instance.update');
    expect($update['payload']['instance']['pitr'])->toBe(['enabled' => true])->and(databases_schema_errors($update))->toBe([]);
    $base = $this->agents->last('db.pitr.base');
    expect(databases_schema_errors($base))->toBe([])
        ->and($base['payload']['encryption']['mode'])->toBe('cp')
        ->and($base['payload']['destination']['url'])->toStartWith('https://')
        ->and(json_encode($base['payload']))->not->toContain('super-secret-access-key-value');
    $row = Backup::query()->where('type', Backup::BASE)->firstOrFail();
    expect($row->status)->toBe(BackupStatus::Pending)->and($row->wrapped_key)->not->toBeNull()
        ->and(app(BackupKeys::class)->unwrap($row->wrapped_key, $this->organization->id, $row->id))->toBe(base64_decode($base['payload']['encryption']['key']));

    // Off: the agent stops shipping (and empties the spool).
    $this->put("/databases/instances/{$this->engine->id}/pitr", ['enabled' => false])->assertSessionHasNoErrors();
    expect($this->agents->last('db.instance.update')['payload']['instance']['pitr'])->toBe(['enabled' => false]);

    // Customer-held: the agent encrypts to the recipient; Falak keeps no key.
    $this->put("/databases/instances/{$this->engine->id}/pitr", ['enabled' => true, 'storage_provider_id' => $this->provider->id, 'encryption_mode' => 'customer', 'age_recipient' => PITR_RECIPIENT])
        ->assertSessionHasNoErrors();
    Backup::query()->update(['status' => BackupStatus::Failed]);
    $this->post("/databases/instances/{$this->engine->id}/pitr/base")->assertSessionHasNoErrors();
    expect($this->agents->last('db.pitr.base')['payload']['encryption'])->toBe(['mode' => 'age', 'key_id' => Backup::query()->latest('id')->value('id'), 'recipient' => PITR_RECIPIENT]);
});

it('lets developers configure PITR but only admins restore, and never Redis', function () {
    [, $developer] = [null, actingAsMember(Role::Developer, $this->organization)];
    $this->put("/databases/instances/{$this->engine->id}/pitr", ['enabled' => true, 'storage_provider_id' => $this->provider->id])->assertSessionHasNoErrors();
    $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => '2026-10-09T11:00:00Z'])->assertForbidden();
    // Less history (off, a shorter window) is what restoring needs: admins only.
    $this->put("/databases/instances/{$this->engine->id}/pitr", ['enabled' => true, 'window_days' => 3, 'base_interval_days' => 1])->assertForbidden();
    $this->put("/databases/instances/{$this->engine->id}/pitr", ['enabled' => false])->assertForbidden();
    $this->put("/databases/instances/{$this->engine->id}/pitr", ['enabled' => true, 'window_days' => 14])->assertSessionHasNoErrors();
    expect($this->engine->refresh()->pitr_enabled)->toBeTrue()->and($this->engine->pitr_window_days)->toBe(14);

    $redis = databases_instance($this->organization, 'redis', $this->server);
    actingAsMember(Role::Admin, $this->organization);
    $this->put("/databases/instances/{$redis->id}/pitr", ['enabled' => true, 'storage_provider_id' => $this->provider->id])->assertSessionHasErrors('pitr');
});

it('is on by default for SQL instances created in production environments', function () {
    $environment = strtolower((string) Str::ulid());
    $projects = Mockery::mock(ProjectDirectory::class);
    $projects->shouldReceive('defaultEnvironment')->andReturn(new EnvironmentData($environment, $this->organization->id, strtolower((string) Str::ulid()), 'production', 'production', true, null));
    $projects->shouldReceive('environment')->andReturn(new EnvironmentData($environment, $this->organization->id, strtolower((string) Str::ulid()), 'production', 'production', true, null));
    app()->instance(ProjectDirectory::class, $projects);

    $this->post('/databases/instances', ['engine' => 'postgresql', 'server_id' => $this->server->id, 'name' => 'orders'])->assertSessionHasNoErrors();
    $created = DatabaseInstance::query()->where('name', 'orders')->firstOrFail();
    expect($created->pitr_enabled)->toBeTrue()->and($created->pitr_storage_provider_id)->toBe($this->provider->id)
        ->and($this->agents->last('db.instance.create')['payload']['instance']['pitr'])->toBe(['enabled' => true]);

    // Its first base once the container runs.
    $this->agents->succeed($this->agents->last('db.instance.create')['handle'], ['changed' => true, 'container_id' => 'c1', 'health' => 'healthy']);
    expect($this->agents->last('db.pitr.base')['payload']['instance'])->toBe($created->id);

    $this->post('/databases/instances', ['engine' => 'redis', 'server_id' => $this->server->id, 'name' => 'cache'])->assertSessionHasNoErrors();
    expect(DatabaseInstance::query()->where('name', 'cache')->value('pitr_enabled'))->toBeFalse();
});

it('hands upload URLs only to the agent of the instance\'s own server and organization', function () {
    pitr_on($this);
    $ask = fn (string $instance, string $kind = 'wal') => ['instance' => $instance, 'kind' => $kind, 'segments' => [['name' => '000000010000000000000004', 'bytes' => 16777216, 'sha256' => str_repeat('d', 64)]]];

    $reply = $this->postJson('/agent/v1/requests/pitr.upload_urls', $ask($this->engine->id), $this->headers)->assertOk()->json('segments.0');
    $segment = PitrSegment::query()->findOrFail($reply['id']);
    expect($reply['name'])->toBe('000000010000000000000004')
        ->and($reply['url'])->toStartWith('https://')->toContain(rawurlencode($segment->id) === $segment->id ? $segment->id : '')
        ->and($reply['encryption']['mode'])->toBe('cp')->and($reply['encryption']['key_id'])->toBe($segment->id)
        // The data key travels in the reply only; the row keeps it sealed for this organization and segment.
        ->and(app(BackupKeys::class)->unwrap($segment->wrapped_key, $this->organization->id, $segment->id, $this->engine->id))->toBe(base64_decode($reply['encryption']['key']))
        ->and(json_encode($reply))->not->toContain('super-secret-access-key-value')
        ->and($segment->shipped_at)->toBeNull();

    // Another server's instance of the same organization, another organization's, the wrong log kind: refused alike.
    $other = databases_instance($this->organization, 'postgresql', databases_server($this->organization), ['pitr_enabled' => true, 'pitr_storage_provider_id' => $this->provider->id]);
    $foreignOrg = Organization::factory()->create();
    $foreign = databases_instance($foreignOrg, 'postgresql', databases_server($foreignOrg), ['pitr_enabled' => true, 'pitr_storage_provider_id' => databases_provider($foreignOrg)->id]);
    foreach ([$ask($other->id), $ask($foreign->id), $ask($this->engine->id, 'binlog'), $ask(strtolower((string) Str::ulid()))] as $body) {
        $this->postJson('/agent/v1/requests/pitr.upload_urls', $body, $this->headers)->assertStatus(409)->assertJson(['error' => 'unknown_instance']);
    }
    expect(PitrSegment::query()->whereIn('database_instance_id', [$other->id, $foreign->id])->exists())->toBeFalse();

    // Without an mTLS certificate nothing is answered.
    $this->postJson('/agent/v1/requests/pitr.upload_urls', $ask($this->engine->id))->assertUnauthorized();

    // PITR off: nothing ships.
    $this->engine->forceFill(['pitr_enabled' => false])->save();
    $this->postJson('/agent/v1/requests/pitr.upload_urls', $ask($this->engine->id), $this->headers)->assertStatus(409)->assertJson(['error' => 'pitr_disabled']);

    // Customer-held without a valid recipient: refused, never Falak-held keys instead.
    $this->engine->forceFill(['pitr_enabled' => true, 'pitr_encryption_mode' => 'customer', 'pitr_age_recipient' => null])->save();
    $this->postJson('/agent/v1/requests/pitr.upload_urls', $ask($this->engine->id), $this->headers)->assertStatus(409)->assertJson(['error' => 'bad_recipient']);
    $this->engine->forceFill(['pitr_age_recipient' => PITR_RECIPIENT])->save();
    $reply = $this->postJson('/agent/v1/requests/pitr.upload_urls', [...$ask($this->engine->id), 'segments' => [['name' => '000000010000000000000009', 'bytes' => 1, 'sha256' => str_repeat('f', 64)]]], $this->headers)->assertOk()->json('segments.0');
    expect($reply['encryption'])->toBe(['mode' => 'age', 'key_id' => $reply['id'], 'recipient' => PITR_RECIPIENT])
        ->and(PitrSegment::query()->findOrFail($reply['id'])->wrapped_key)->toBeNull();
});

it('acknowledges only the segments it handed out, and answers a file it already has as shipped', function () {
    pitr_on($this);
    $sha = str_repeat('d', 64);
    $slot = $this->postJson('/agent/v1/requests/pitr.upload_urls', ['instance' => $this->engine->id, 'kind' => 'wal', 'segments' => [['name' => '000000010000000000000004', 'bytes' => 16, 'sha256' => $sha]]], $this->headers)->json('segments.0');
    $stored = 'FKB1 encrypted segment';
    $item = ['id' => $slot['id'], 'name' => '000000010000000000000004', 'size_bytes' => strlen($stored), 'sha256' => hash('sha256', $stored), 'plaintext_bytes' => 16, 'plaintext_sha256' => $sha, 'end_time' => '2026-10-09T11:59:30.250Z'];
    $ack = fn (array $segment) => $this->postJson('/agent/v1/requests/pitr.shipped', ['instance' => $this->engine->id, 'kind' => 'wal', 'segments' => [$segment]], $this->headers);

    // Asked again before it shipped: the same row and key, never a second one.
    $again = $this->postJson('/agent/v1/requests/pitr.upload_urls', ['instance' => $this->engine->id, 'kind' => 'wal', 'segments' => [['name' => '000000010000000000000004', 'bytes' => 16, 'sha256' => $sha]]], $this->headers)->json('segments.0');
    expect($again['id'])->toBe($slot['id'])->and($again['encryption']['key'])->toBe($slot['encryption']['key'])
        ->and(PitrSegment::query()->count())->toBe(1);

    // Not in the storage yet, or not what the agent says: not acknowledged (it keeps the file and uploads it again).
    $ack($item)->assertOk()->assertJson(['acknowledged' => []]);
    $key = PitrSegment::query()->findOrFail($slot['id'])->object_key;
    $this->bucket[$key] = 'something else entirely';
    $ack($item)->assertOk()->assertJson(['acknowledged' => []]);
    $this->bucket[$key] = strrev($stored); // the same size, another content
    $ack($item)->assertOk()->assertJson(['acknowledged' => []]);
    $this->bucket[$key] = $stored;

    // Another content, another name, an id of nothing: not acknowledged (the agent keeps the file).
    $ack([...$item, 'plaintext_sha256' => str_repeat('e', 64)])->assertOk()->assertJson(['acknowledged' => []]);
    $ack([...$item, 'name' => '000000010000000000000005'])->assertOk()->assertJson(['acknowledged' => []]);
    $ack([...$item, 'id' => strtolower((string) Str::ulid())])->assertOk()->assertJson(['acknowledged' => []]);
    expect(PitrSegment::query()->findOrFail($slot['id'])->shipped_at)->toBeNull();

    $ack($item)->assertOk()->assertJson(['acknowledged' => [$slot['id']]]);
    $segment = PitrSegment::query()->findOrFail($slot['id']);
    expect($segment->shipped_at)->not->toBeNull()->and($segment->sha256)->toBe(hash('sha256', $stored))
        ->and($segment->end_time->toIso8601ZuluString('millisecond'))->toBe('2026-10-09T11:59:30.250Z')
        ->and($this->engine->refresh()->pitr_last_shipped_at)->not->toBeNull();

    // The same file asked again (its acknowledgment was lost): shipped, no new URL.
    $again = $this->postJson('/agent/v1/requests/pitr.upload_urls', ['instance' => $this->engine->id, 'kind' => 'wal', 'segments' => [['name' => '000000010000000000000004', 'bytes' => 16, 'sha256' => $sha]]], $this->headers)->json('segments.0');
    expect($again)->toBe(['name' => '000000010000000000000004', 'id' => $slot['id'], 'shipped' => true]);

    // An agent of another server can't acknowledge it.
    $intruder = pitr_enroll($this->organization->id, databases_server($this->organization)->id);
    $this->postJson('/agent/v1/requests/pitr.shipped', ['instance' => $this->engine->id, 'kind' => 'wal', 'segments' => [$item]], fleet_mtls($intruder['fingerprint']))
        ->assertStatus(409);
});

it('records gaps, alerts and starts a new base', function () {
    Event::fake([PitrAlert::class]);
    pitr_on($this);
    [, , $mysql] = databases_service($this->organization, 'mysql', 'orders', $this->server);
    $mysql->forceFill(['pitr_enabled' => true, 'pitr_storage_provider_id' => $this->provider->id])->save();

    $this->postJson('/agent/v1/requests/pitr.gap', ['instance' => $mysql->id, 'kind' => 'binlog', 'gaps' => [
        ['kind' => 'reset', 'from' => 'binlog.000009', 'to' => 'binlog.000001', 'detail' => 'the binlogs were reset'],
    ]], $this->headers)->assertOk();

    expect(PitrGap::query()->where('database_instance_id', $mysql->id)->value('gap'))->toBe('reset')
        ->and($this->agents->last('db.pitr.base')['payload']['instance'])->toBe($mysql->id);
    Event::assertDispatched(PitrAlert::class, fn (PitrAlert $alert) => $alert->type === PitrAlert::GAP && $alert->instanceId === $mysql->id
        && $alert->toAlert()->dedupKey === "databases.pitr.gap:{$mysql->id}");

    // The base succeeds: the gap is behind it, the alert resolves.
    $command = $this->agents->last('db.pitr.base');
    $this->agents->succeed($command['handle'], ['size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'location' => 'x', 'plaintext_sha256' => str_repeat('b', 64),
        'encryption' => 'cp', 'key_id' => $command['payload']['encryption']['key_id'], 'cipher' => 'aes-256-gcm', 'compression' => 'zstd',
        'started_at' => '2026-10-09T12:00:01Z', 'finished_at' => '2026-10-09T12:00:09Z', 'start_binlog' => 'binlog.000002']);
    $base = Backup::query()->findOrFail($command['payload']['encryption']['key_id']);
    expect($base->status)->toBe(BackupStatus::Succeeded)->and($base->log_start)->toBe('binlog.000002')
        ->and($base->base_finished_at->toIso8601ZuluString())->toBe('2026-10-09T12:00:09Z')
        ->and(PitrGap::query()->whereNull('resolved_at')->exists())->toBeFalse()
        ->and($this->agents->commands[$command['handle']->id]['payload']['encryption']['key'])->toBe('[forgotten]');
    Event::assertDispatched(PitrAlert::class, fn (PitrAlert $alert) => $alert->type === PitrAlert::RECOVERED && $alert->resolves === PitrAlert::GAP);
});

it('alerts when a base backup fails', function () {
    Event::fake([PitrAlert::class]);
    pitr_on($this);
    $this->post("/databases/instances/{$this->engine->id}/pitr/base")->assertSessionHasNoErrors();
    $this->agents->fail($this->agents->last('db.pitr.base')['handle'], 'pg_basebackup: could not connect');

    expect(Backup::query()->where('type', Backup::BASE)->value('status'))->toBe(BackupStatus::Failed)
        ->and($this->engine->refresh()->pitr_next_base_at->toDateTimeString())->toBe('2026-10-09 13:00:00');
    Event::assertDispatched(PitrAlert::class, fn (PitrAlert $alert) => $alert->type === PitrAlert::BASE_FAILED && str_contains($alert->body, 'could not connect'));
});

it('builds the timeline from the bases and segments, with gaps ending a range', function () {
    pitr_on($this);
    pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30');
    pitr_ship($this, '000000010000000000000002', '2026-10-09T07:59:00Z'); // before the base: not needed
    pitr_ship($this, '000000010000000000000003', '2026-10-09T08:01:00Z');
    pitr_ship($this, '000000010000000000000004', '2026-10-09T09:00:00Z');
    PitrGap::query()->create(['organization_id' => $this->organization->id, 'database_instance_id' => $this->engine->id, 'kind' => 'wal', 'gap' => 'missing',
        'detail' => 'segments lost', 'detected_at' => Carbon::parse('2026-10-09 09:30:00')]);
    pitr_ship($this, '000000010000000000000006', '2026-10-09T10:00:00Z'); // after the gap: needs a newer base
    pitr_base($this, '2026-10-09 10:30:00', '2026-10-09 10:30:20', '000000010000000000000007');
    pitr_ship($this, '000000010000000000000007', '2026-10-09T10:31:00Z');
    pitr_ship($this, '000000010000000000000008', '2026-10-09T11:00:00Z');

    $timeline = app(PitrTimeline::class)->for($this->engine);
    expect($timeline['ranges'])->toHaveCount(2)
        ->and($timeline['ranges'][0]['from'])->toBe('2026-10-09T08:00:30.000Z')->and($timeline['ranges'][0]['to'])->toBe('2026-10-09T09:00:00.000Z')
        ->and($timeline['ranges'][1]['from'])->toBe('2026-10-09T10:30:20.000Z')->and($timeline['ranges'][1]['to'])->toBe('2026-10-09T11:00:00.000Z')
        ->and($timeline['gaps'][0]['detail'])->toBe('segments lost')
        ->and($timeline['from'])->toBe('2026-10-09T08:00:30.000Z')->and($timeline['to'])->toBe('2026-10-09T11:00:00.000Z');

    $plan = app(PitrTimeline::class)->plan($this->engine, Carbon::parse('2026-10-09 08:30:00')->toImmutable());
    expect(array_map(fn ($s) => $s->name, $plan['segments']))->toBe(['000000010000000000000003', '000000010000000000000004']);
    expect(app(PitrTimeline::class)->plan($this->engine, Carbon::parse('2026-10-09 09:45:00')->toImmutable()))->toBeNull()
        ->and(app(PitrTimeline::class)->plan($this->engine, Carbon::parse('2026-10-09 10:45:00')->toImmutable())['segments'])->toHaveCount(2);

    // The instance page shows it.
    $this->get("/databases/instances/{$this->engine->id}")->assertInertia(fn ($page) => $page->where('pitr.enabled', true)->has('pitr.timeline.ranges', 2)->has('pitr.bases', 2));
});

it('keeps everything the oldest in-window base needs and prunes the rest from storage', function () {
    pitr_on($this, ['pitr_window_days' => 2]);
    $old = pitr_base($this, '2026-10-05 00:00:00', '2026-10-05 00:01:00', '000000010000000000000001'); // before the one covering the window start: pruned
    $covering = pitr_base($this, '2026-10-06 00:00:00', '2026-10-06 00:01:00');                     // newest before the window (Oct 7 12:00): kept
    $recent = pitr_base($this, '2026-10-08 00:00:00', '2026-10-08 00:01:00', '000000010000000000000005');
    $gone = pitr_ship($this, '000000010000000000000001', '2026-10-05T12:00:00Z');
    $needed = pitr_ship($this, '000000010000000000000003', '2026-10-06T00:02:00Z');
    pitr_ship($this, '000000010000000000000004', '2026-10-07T23:00:00Z');
    pitr_ship($this, '000000010000000000000005', '2026-10-08T00:02:00Z');
    $fresh = pitr_ship($this, '000000010000000000000006', '2026-10-09T11:00:00Z');

    app(PrunePitr::class)($this->engine);

    expect($old->refresh()->status)->toBe(BackupStatus::Pruned)
        ->and($covering->refresh()->status)->toBe(BackupStatus::Succeeded)
        ->and($recent->refresh()->status)->toBe(BackupStatus::Succeeded)
        ->and(PitrSegment::query()->find($gone->id))->toBeNull()
        ->and(PitrSegment::query()->find($needed->id))->not->toBeNull()
        ->and(PitrSegment::query()->find($fresh->id))->not->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), $gone->id));
    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), $needed->id));
    // The window still starts at a recoverable point.
    expect(app(PitrTimeline::class)->plan($this->engine, Carbon::parse('2026-10-07 12:00:00')->toImmutable()))->not->toBeNull();
});

it('restores to a time into a new read-only instance, then swaps it in keeping the old one', function () {
    Event::fake([PitrAlert::class]);
    pitr_on($this);
    $schedule = BackupSchedule::query()->create(['organization_id' => $this->organization->id, 'database_instance_id' => $this->engine->id, 'storage_provider_id' => $this->provider->id,
        'name' => 'Nightly', 'cron' => '0 3 * * *', 'enabled' => true]);
    $base = pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30');
    pitr_ship($this, '000000010000000000000003', '2026-10-09T08:01:00Z');
    pitr_ship($this, '000000010000000000000004', '2026-10-09T09:00:00Z');
    pitr_ship($this, '000000010000000000000005', '2026-10-09T10:00:00Z');

    $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => '2026-10-09T07:00:00Z'])->assertStatus(422)->assertJsonValidationErrors('target_time');
    $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => '2026-10-09T08:30:00.123Z'])->assertStatus(202);

    $restore = Restore::query()->where('type', Restore::PITR)->firstOrFail();
    $copy = DatabaseInstance::query()->findOrFail($restore->restored_instance_id);
    $command = $this->agents->last('db.pitr.restore');
    expect(databases_schema_errors($command))->toBe([])
        ->and($copy->status)->toBe(InstanceStatus::Pending)->and($copy->restored_from)->toBe($this->engine->id)
        ->and($copy->image_digest)->toBe($this->engine->image_digest)->and($copy->volume_id)->not->toBe($this->engine->volume_id)
        ->and($copy->environment_id)->toBeNull()->and($copy->pitr_enabled)->toBeFalse()
        ->and($command['payload']['instance']['id'])->toBe($copy->id)
        ->and($command['payload']['instance'])->not->toHaveKey('network')
        ->and($command['payload']['target_time'])->toBe('2026-10-09T08:30:00.123000Z')
        ->and($command['payload']['base']['sha256'])->toBe($base->sha256)
        ->and(array_column($command['payload']['segments'], 'name'))->toBe(['000000010000000000000003', '000000010000000000000004'])
        ->and($command['payload']['databases'])->toBe(['shop'])
        ->and($this->agents->last('volume.create'))->not->toBeNull();
    // Each object opens with its own key.
    $first = PitrSegment::query()->where('name', '000000010000000000000003')->firstOrFail();
    expect(base64_decode($command['payload']['segments'][0]['encryption']['key']))->toBe(app(BackupKeys::class)->unwrap($first->wrapped_key, $this->organization->id, $first->id, $this->engine->id));

    // One at a time.
    $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => '2026-10-09T08:40:00Z'])->assertStatus(422);

    $this->agents->succeed($command['handle'], ['container_id' => 'c9', 'health' => 'healthy', 'recovered_to' => '2026-10-09T08:30:00.123Z', 'segments' => 2,
        'downloaded_bytes' => 1200, 'table_counts' => ['shop' => ['public.orders' => 42]], 'duration_ms' => 9000]);
    expect($restore->refresh()->status)->toBe(RestoreStatus::AwaitingDecision)->and($restore->table_counts)->toBe(['shop' => ['public.orders' => 42]])
        ->and($copy->refresh()->status)->toBe(InstanceStatus::Inspecting)
        ->and($this->agents->commands[$command['handle']->id]['payload']['segments'][0]['encryption']['key'])->toBe('[forgotten]')
        ->and($this->agents->commands[$command['handle']->id]['payload']['password'])->toBe('[forgotten]');

    $this->get("/databases/instances/{$this->engine->id}")->assertInertia(fn ($page) => $page
        ->where('pitr.restores.0.status', 'awaiting_decision')
        ->where('pitr.restores.0.copy.host', '127.0.0.1')
        ->where('pitr.restores.0.copy.port', $copy->host_port)
        ->where('pitr.restores.0.table_counts', ['shop' => ['public.orders' => 42]]));

    // Swap: the copy is made writable and the old one stopped, then the copy takes over.
    $old = ['name' => $this->engine->name, 'hostname' => $this->engine->hostname, 'host_port' => $this->engine->host_port];
    $this->postJson("/databases/pitr-restores/{$restore->id}/decision", ['decision' => 'swap'])->assertOk();
    $promote = $this->agents->last('db.pitr.promote');
    expect($promote['payload'])->toBe(['instance' => $copy->id, 'engine' => 'postgres', 'stop' => $this->engine->id, 'inspection_user' => 'falak_inspect'])
        ->and($restore->refresh()->status)->toBe(RestoreStatus::Running);
    $this->agents->succeed($promote['handle'], ['changed' => true]);

    $copy->refresh();
    $source = $this->engine->refresh();
    expect($restore->refresh()->status)->toBe(RestoreStatus::Succeeded)->and($restore->decision)->toBe('swap')
        ->and($copy->status)->toBe(InstanceStatus::Active)->and($copy->name)->toBe($old['name'])->and($copy->hostname)->toBe($old['hostname'])
        ->and($copy->host_port)->toBe($old['host_port'])->and($copy->pitr_enabled)->toBeTrue()
        ->and($source->status)->toBe(InstanceStatus::Retired)->and($source->replaced_by)->toBe($copy->id)
        // Kept: stopped with its volume, its container is not removed later.
        ->and($source->retire_at)->toBeNull()->and($source->volume_id)->not->toBeNull()->and($source->pitr_enabled)->toBeFalse()
        ->and($copy->settings)->toBe($source->settings)
        ->and(Database::query()->findOrFail($this->db->id)->database_instance_id)->toBe($copy->id)
        ->and(DatabaseUser::query()->findOrFail($this->appUser->id)->database_instance_id)->toBe($copy->id)
        ->and($schedule->refresh()->database_instance_id)->toBe($copy->id)
        ->and($this->agents->last('db.instance.update')['payload']['instance']['id'])->toBe($copy->id)
        // Its history starts with a base of its own.
        ->and($this->agents->last('db.pitr.base')['payload']['instance'])->toBe($copy->id);
});

it('keeps a restored copy as a new database on the canvas, or discards it', function () {
    Event::fake([DatabaseCreated::class]);
    pitr_on($this);
    pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30');
    pitr_ship($this, '000000010000000000000003', '2026-10-09T09:00:00Z');
    $restoreTo = function () {
        $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => '2026-10-09T08:30:00Z'])->assertStatus(202);
        $command = $this->agents->last('db.pitr.restore');
        $this->agents->succeed($command['handle'], ['container_id' => 'c9', 'health' => 'healthy', 'recovered_to' => 'x', 'segments' => 1, 'downloaded_bytes' => 1, 'table_counts' => []]);

        return Restore::query()->latest('id')->firstOrFail();
    };

    $restore = $restoreTo();
    $this->postJson("/databases/pitr-restores/{$restore->id}/decision", ['decision' => 'keep'])->assertOk();
    expect($this->agents->last('db.pitr.promote')['payload'])->toBe(['instance' => $restore->restored_instance_id, 'engine' => 'postgres', 'inspection_user' => 'falak_inspect']);
    $this->agents->succeed($this->agents->last('db.pitr.promote')['handle'], ['changed' => true]);
    $copy = DatabaseInstance::query()->findOrFail($restore->restored_instance_id);
    expect($copy->status)->toBe(InstanceStatus::Active)->and($copy->environment_id)->toBe($this->engine->environment_id)
        ->and($copy->databases()->pluck('name')->all())->toBe(['shop'])->and($copy->users()->pluck('username')->all())->toBe(['shop'])
        ->and($this->engine->refresh()->status)->toBe(InstanceStatus::Active)
        ->and($this->db->refresh()->database_instance_id)->toBe($this->engine->id);
    Event::assertDispatched(DatabaseCreated::class, fn (DatabaseCreated $event) => $event->databaseId === $copy->databases()->value('id'));

    // Decided once only.
    $this->postJson("/databases/pitr-restores/{$restore->id}/decision", ['decision' => 'discard'])->assertStatus(422);

    $restore = $restoreTo();
    $this->postJson("/databases/pitr-restores/{$restore->id}/decision", ['decision' => 'discard'])->assertOk();
    expect($restore->refresh()->status)->toBe(RestoreStatus::Discarded)
        ->and($this->agents->last('db.instance.delete')['payload'])->toBe(['id' => $restore->restored_instance_id])
        ->and(DatabaseInstance::query()->findOrFail($restore->restored_instance_id)->delete_volume)->toBeTrue();
});

it('removes the copy of a failed restore', function () {
    pitr_on($this);
    pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30');
    pitr_ship($this, '000000010000000000000003', '2026-10-09T09:00:00Z');
    $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => '2026-10-09T08:30:00Z'])->assertStatus(202);
    $this->agents->fail($this->agents->last('db.pitr.restore')['handle'], 'segment 000000010000000000000003: sha256 mismatch');

    $restore = Restore::query()->firstOrFail();
    expect($restore->status)->toBe(RestoreStatus::Failed)->and($restore->error)->toContain('sha256 mismatch')
        ->and($this->agents->last('db.instance.delete')['payload'])->toBe(['id' => $restore->restored_instance_id]);
});

it('asks for the customer\'s identity once and never keeps it', function () {
    pitr_on($this, ['pitr_encryption_mode' => 'customer', 'pitr_age_recipient' => PITR_RECIPIENT]);
    pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30', mode: 'customer');
    $segment = pitr_ship($this, '000000010000000000000003', '2026-10-09T09:00:00Z');
    expect($segment->encryption_mode)->toBe('customer')->and($segment->wrapped_key)->toBeNull();

    $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => '2026-10-09T08:30:00Z'])->assertStatus(422)->assertJsonValidationErrors('identity');
    $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => '2026-10-09T08:30:00Z', 'identity' => pitr_identity()])->assertStatus(202);

    $command = $this->agents->last('db.pitr.restore');
    expect($command['payload']['identity'])->toBe(pitr_identity())
        ->and($command['payload']['base']['encryption'])->toBe(['mode' => 'age', 'key_id' => Backup::query()->where('type', 'base')->value('id')])
        ->and($command['payload']['segments'][0]['encryption'])->toBe(['mode' => 'age', 'key_id' => $segment->id])
        ->and(databases_schema_errors($command))->toBe([]);

    $this->agents->fail($command['handle'], 'the age identity does not match');
    expect($this->agents->commands[$command['handle']->id]['payload']['identity'])->toBe('[forgotten]');
    foreach (['databases_instances', 'databases_restores', 'databases_backups', 'databases_pitr_segments', 'audit_logs'] as $table) {
        if (Schema::hasTable($table)) {
            expect(json_encode(DB::table($table)->get()))->not->toContain(pitr_identity());
        }
    }
    expect(session()->all())->not->toHaveKey('_old_input');
});

it('alerts on a lagging or full spool from the heartbeat, once, and resolves', function () {
    Event::fake([PitrAlert::class]);
    pitr_on($this, ['pitr_next_base_at' => now()->addDay()]);
    $this->engine->forceFill(['health' => 'healthy'])->save();
    $report = fn (array $pitr) => event(new AgentDatabasesReported('a', $this->organization->id, $this->server->id, [
        ['id' => $this->engine->id, 'state' => 'running', 'health' => 'healthy', 'secrets_missing' => false, 'pitr' => $pitr],
    ]));

    $report(['spool_bytes' => 3 * 1024 ** 3, 'volume_bytes' => 10 * 1024 ** 3, 'pending' => 40, 'oldest_pending_at' => now()->subMinutes(12)->toIso8601ZuluString(), 'error' => 'HTTP 502']);
    (new MaintainPitr)->handle(app(TakePitrBase::class), app(PrunePitr::class));
    (new MaintainPitr)->handle(app(TakePitrBase::class), app(PrunePitr::class));
    Event::assertDispatchedTimes(PitrAlert::class, 2);
    Event::assertDispatched(PitrAlert::class, fn (PitrAlert $alert) => $alert->type === PitrAlert::LAG && str_contains($alert->body, 'HTTP 502'));
    Event::assertDispatched(PitrAlert::class, fn (PitrAlert $alert) => $alert->type === PitrAlert::SPOOL_FULL && str_contains($alert->body, '30%'));
    expect($this->engine->refresh()->pitr_report['pending'])->toBe(40);

    $report(['spool_bytes' => 1024, 'volume_bytes' => 10 * 1024 ** 3, 'pending' => 0]);
    (new MaintainPitr)->handle(app(TakePitrBase::class), app(PrunePitr::class));
    Event::assertDispatched(PitrAlert::class, fn (PitrAlert $alert) => $alert->type === PitrAlert::RECOVERED && $alert->resolves === PitrAlert::LAG && $alert->toAlert()->resolves);
    Event::assertDispatched(PitrAlert::class, fn (PitrAlert $alert) => $alert->type === PitrAlert::RECOVERED && $alert->resolves === PitrAlert::SPOOL_FULL);
    Event::assertDispatchedTimes(PitrAlert::class, 4);
});

it('takes due base backups', function () {
    pitr_on($this, ['pitr_next_base_at' => now()->subMinute()]);
    (new MaintainPitr)->handle(app(TakePitrBase::class), app(PrunePitr::class));
    expect($this->agents->dispatched('db.pitr.base'))->toHaveCount(1)
        ->and($this->engine->refresh()->pitr_next_base_at->toDateTimeString())->toBe('2026-10-16 12:00:00');
    // One at a time.
    $this->engine->forceFill(['pitr_next_base_at' => now()->subMinute()])->save();
    (new MaintainPitr)->handle(app(TakePitrBase::class), app(PrunePitr::class));
    expect($this->agents->dispatched('db.pitr.base'))->toHaveCount(1);
});

it('ends a range at a missing WAL segment and shows the hole', function () {
    pitr_on($this);
    pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30');
    pitr_ship($this, '000000010000000000000003', '2026-10-09T08:01:00Z');
    pitr_ship($this, '000000010000000000000004', '2026-10-09T09:00:00Z');
    // 05 never arrived (the spool lost it): 06 can't be replayed.
    pitr_ship($this, '000000010000000000000006', '2026-10-09T10:00:00Z');

    $timeline = app(PitrTimeline::class)->for($this->engine);
    expect($timeline['ranges'])->toHaveCount(1)->and($timeline['ranges'][0]['to'])->toBe('2026-10-09T09:00:00.000Z')
        ->and(collect($timeline['gaps'])->pluck('detail')->join(' '))->toContain('000000010000000000000004');
    expect(app(PitrTimeline::class)->plan($this->engine, Carbon::parse('2026-10-09 09:30:00')->toImmutable())['latest'])->toBeTrue()
        ->and(array_map(fn ($s) => $s->name, app(PitrTimeline::class)->plan($this->engine, null)['segments']))->toBe(['000000010000000000000003', '000000010000000000000004']);

    // A base whose first segment never arrived has no range at all.
    pitr_base($this, '2026-10-09 11:00:00', '2026-10-09 11:00:30', '000000010000000000000007');
    pitr_ship($this, '000000010000000000000008', '2026-10-09T11:30:00Z');
    expect(app(PitrTimeline::class)->for($this->engine)['ranges'])->toHaveCount(1);
});

it('keeps binlogs numbered again after a reset apart (epochs)', function () {
    pitr_on($this);
    [, , $mysql] = databases_service($this->organization, 'mysql', 'orders', $this->server);
    $mysql->forceFill(['pitr_enabled' => true, 'pitr_storage_provider_id' => $this->provider->id])->save();
    $this->engine = $mysql;
    pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30', 'binlog.000004');
    pitr_ship($this, 'binlog.000004', '2026-10-09T08:01:00Z', kind: 'binlog');
    pitr_ship($this, 'binlog.000005', '2026-10-09T08:30:00Z', kind: 'binlog');

    // The binlogs were reset: a new epoch, a new base, the numbering starts over.
    $reset = ['instance' => $mysql->id, 'kind' => 'binlog', 'gaps' => [['kind' => 'reset', 'from' => 'binlog.000005', 'to' => 'binlog.000001', 'detail' => 'reset']]];
    $this->postJson('/agent/v1/requests/pitr.gap', $reset, $this->headers)->assertOk();
    // Reported again (the agent retried): recorded once.
    $this->postJson('/agent/v1/requests/pitr.gap', $reset, $this->headers)->assertOk();
    $mysql->refresh();
    expect($mysql->pitr_epoch)->toBe(1)->and(PitrGap::query()->where('database_instance_id', $mysql->id)->count())->toBe(1);
    $this->engine = $mysql;
    pitr_base($this, '2026-10-09 09:00:00', '2026-10-09 09:00:30', 'binlog.000004');
    $new4 = pitr_ship($this, 'binlog.000004', '2026-10-09T09:05:00Z', kind: 'binlog');
    pitr_ship($this, 'binlog.000005', '2026-10-09T09:30:00Z', kind: 'binlog');

    expect($new4->epoch)->toBe(1);
    $plan = app(PitrTimeline::class)->plan($mysql, Carbon::parse('2026-10-09 09:10:00')->toImmutable());
    expect($plan['segments'][0]->id)->toBe($new4->id)->and(collect($plan['segments'])->every(fn ($s) => $s->epoch === 1))->toBeTrue();
    $old = app(PitrTimeline::class)->plan($mysql, Carbon::parse('2026-10-09 08:10:00')->toImmutable());
    expect(collect($old['segments'])->every(fn ($s) => $s->epoch === 0))->toBeTrue();
});

it('restores to the latest point, and treats a target at the end of the log as the latest', function () {
    pitr_on($this);
    pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30');
    pitr_ship($this, '000000010000000000000003', '2026-10-09T08:01:00Z');
    pitr_ship($this, '000000010000000000000004', '2026-10-09T09:00:00Z');

    $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => 'latest'])->assertStatus(202);
    $command = $this->agents->last('db.pitr.restore');
    $restore = Restore::query()->latest('id')->firstOrFail();
    expect($command['payload'])->not->toHaveKey('target_time')
        ->and(array_column($command['payload']['segments'], 'name'))->toBe(['000000010000000000000003', '000000010000000000000004'])
        ->and($restore->to_latest)->toBeTrue()->and($restore->target_time->toIso8601ZuluString())->toBe('2026-10-09T09:00:00Z')
        // The copy is read-only in its config; people inspect it through a read-only account.
        ->and($command['payload']['instance']['settings'])->toMatchArray(['read_only' => true, 'event_scheduler' => false])
        ->and($command['payload']['inspection']['username'])->toBe('falak_inspect')
        ->and(databases_schema_errors($command))->toBe([]);
    $this->agents->fail($command['handle'], 'x');

    $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => '2026-10-09T09:00:00Z'])->assertStatus(202);
    expect($this->agents->last('db.pitr.restore')['payload'])->not->toHaveKey('target_time');
});

it('shows the copy\'s read-only account to whoever may restore, and never in the page', function () {
    pitr_on($this);
    pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30');
    pitr_ship($this, '000000010000000000000003', '2026-10-09T09:00:00Z');
    $this->postJson("/databases/instances/{$this->engine->id}/pitr/restore", ['target_time' => '2026-10-09T08:30:00Z'])->assertStatus(202);
    $command = $this->agents->last('db.pitr.restore');
    $password = $command['payload']['inspection']['password'];
    $restore = Restore::query()->firstOrFail();
    $this->postJson("/databases/pitr-restores/{$restore->id}/inspection")->assertStatus(422);
    $this->agents->succeed($command['handle'], ['container_id' => 'c9', 'health' => 'healthy', 'recovered_to' => 'x', 'segments' => 1, 'downloaded_bytes' => 1, 'table_counts' => []]);

    expect($this->agents->commands[$command['handle']->id]['payload']['inspection']['password'])->toBe('[forgotten]')
        ->and((string) DB::table('databases_restores')->value('inspection_password'))->not->toContain($password);
    $this->postJson("/databases/pitr-restores/{$restore->id}/inspection")->assertOk()->assertJson(['data' => ['username' => 'falak_inspect', 'password' => $password]]);
    $this->get("/databases/instances/{$this->engine->id}")->assertDontSee($password);

    actingAsMember(Role::Developer, $this->organization);
    $this->postJson("/databases/pitr-restores/{$restore->id}/inspection")->assertForbidden();
});

it('binds segment keys to their instance', function () {
    pitr_on($this);
    $segment = pitr_ship($this, '000000010000000000000003', '2026-10-09T09:00:00Z');
    expect(fn () => app(BackupKeys::class)->unwrap($segment->wrapped_key, $this->organization->id, $segment->id))->toThrow(DecryptionFailed::class)
        ->and(fn () => app(BackupKeys::class)->unwrap($segment->wrapped_key, $this->organization->id, $segment->id, strtolower((string) Str::ulid())))->toThrow(DecryptionFailed::class);
});

it('caps the segments handed out but never shipped, and rate-limits gaps', function () {
    config(['databases.pitr.max_pending' => 2, 'databases.pitr.gaps_per_hour' => 2]);
    pitr_on($this);
    $ask = fn (string $name) => $this->postJson('/agent/v1/requests/pitr.upload_urls', ['instance' => $this->engine->id, 'kind' => 'wal', 'segments' => [['name' => $name, 'bytes' => 1, 'sha256' => hash('sha256', $name)]]], $this->headers);
    $ask('000000010000000000000001')->assertOk();
    $ask('000000010000000000000002')->assertOk();
    $ask('000000010000000000000003')->assertStatus(409)->assertJson(['error' => 'too_many_pending']);
    // Asked again: still answered (no new row).
    $ask('000000010000000000000001')->assertOk();

    $gap = fn (string $from) => $this->postJson('/agent/v1/requests/pitr.gap', ['instance' => $this->engine->id, 'kind' => 'wal', 'gaps' => [['kind' => 'missing', 'from' => $from, 'detail' => 'lost']]], $this->headers);
    $gap('a')->assertOk();
    $gap('b')->assertOk();
    $gap('c')->assertStatus(409)->assertJson(['error' => 'too_many_gaps']);
});

it('prunes unshipped segments a day after they were last asked for', function () {
    pitr_on($this);
    $ask = fn () => $this->postJson('/agent/v1/requests/pitr.upload_urls', ['instance' => $this->engine->id, 'kind' => 'wal', 'segments' => [['name' => '000000010000000000000001', 'bytes' => 1, 'sha256' => str_repeat('a', 64)]]], $this->headers);
    $id = $ask()->json('segments.0.id');
    $this->travel(30)->hours();
    PitrSegment::query()->whereKey($id)->update(['updated_at' => now()]); // asked again just now
    app(PrunePitr::class)($this->engine);
    expect(PitrSegment::query()->find($id))->not->toBeNull();
    $this->travel(25)->hours();
    app(PrunePitr::class)($this->engine);
    expect(PitrSegment::query()->find($id))->toBeNull();
});

it('keeps the history of an instance that turned PITR off until its window passed', function () {
    pitr_on($this, ['pitr_window_days' => 2]);
    $base = pitr_base($this, '2026-10-08 08:00:00', '2026-10-08 08:00:30');
    $segment = pitr_ship($this, '000000010000000000000003', '2026-10-08T09:00:00Z');
    $this->engine->forceFill(['pitr_enabled' => false])->save();

    app(PrunePitr::class)($this->engine);
    expect($base->refresh()->status)->toBe(BackupStatus::Succeeded)->and(PitrSegment::query()->find($segment->id))->not->toBeNull();

    $this->travel(3)->days();
    app(PrunePitr::class)($this->engine);
    expect($base->refresh()->status)->toBe(BackupStatus::Pruned)->and(PitrSegment::query()->find($segment->id))->toBeNull();
});

it('forgets a deleted instance\'s history, objects first', function () {
    pitr_on($this);
    $base = pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30');
    $this->bucket[$base->object_key] = 'base';
    $segment = pitr_ship($this, '000000010000000000000003', '2026-10-09T09:00:00Z');

    $this->delete("/databases/instances/{$this->engine->id}", ['confirm' => $this->engine->name])->assertSessionHasNoErrors();
    $this->agents->succeed($this->agents->last('db.instance.delete')['handle'], ['changed' => true]);

    expect(DatabaseInstance::query()->find($this->engine->id))->toBeNull()
        ->and(PitrSegment::query()->find($segment->id))->toBeNull()
        ->and($base->refresh()->status)->toBe(BackupStatus::Pruned)
        ->and(iterator_to_array($this->deleted))->toContain($segment->object_key, $base->object_key);
});

it('brings a lost server\'s PITR database back on another server at the latest point (DatabaseRecovery)', function () {
    pitr_on($this);
    pitr_base($this, '2026-10-09 08:00:00', '2026-10-09 08:00:30');
    pitr_ship($this, '000000010000000000000003', '2026-10-09T09:00:00Z');
    pitr_ship($this, '000000010000000000000004', '2026-10-09T11:40:00Z');
    $recovery = app(DatabaseRecovery::class);
    $target = databases_server($this->organization, attributes: ['name' => 'app-new']);

    // The data loss is the PITR lag (time since the last shipped segment), not a backup's age.
    $point = $recovery->points([$this->db->id])[$this->db->id];
    expect($point->usesPitr())->toBeTrue()
        ->and($point->pitrLatestAt?->format(DATE_ATOM))->toBe('2026-10-09T11:40:00+00:00')
        ->and($point->dataLossSeconds(now()->toDateTimeImmutable()))->toBe(20 * 60);

    // Relocated with shipping off: the empty container's own log never joins the history.
    $recovery->relocate($this->engine->id, $target->id, suspendPitr: true);
    $instance = $this->engine->refresh();
    $create = $this->agents->last('db.instance.create', $target->id);
    expect($instance->server_id)->toBe($target->id)->and($instance->pitr_enabled)->toBeFalse()
        ->and($create['payload']['instance']['pitr'])->toBe(['enabled' => false]);
    $bases = count($this->agents->dispatched('db.pitr.base'));
    $this->agents->succeed($create['handle'], ['changed' => true, 'container_id' => 'c1', 'health' => 'healthy']);
    foreach ($this->agents->dispatched('db.create', $target->id) as $command) {
        $this->agents->succeed($command['handle']);
    }
    expect($recovery->progress($instance->id)['state'])->toBe('ready')
        ->and(count($this->agents->dispatched('db.pitr.base')))->toBe($bases);

    // Restored to the latest point on the new server, swapped in, PITR on again for the restored instance.
    $restoreId = $recovery->restoreToLatest($instance->id);
    $command = $this->agents->last('db.pitr.restore', $target->id);
    expect($command['payload'])->not->toHaveKey('target_time')
        ->and(array_column($command['payload']['segments'], 'name'))->toBe(['000000010000000000000003', '000000010000000000000004']);
    expect($recovery->pitrProgress($restoreId)['state'])->toBe('running');
    $this->agents->succeed($command['handle'], ['container_id' => 'c9', 'health' => 'healthy', 'recovered_to' => '2026-10-09T11:40:00Z', 'segments' => 2, 'downloaded_bytes' => 1, 'table_counts' => []]);

    expect($recovery->pitrProgress($restoreId)['state'])->toBe('running');
    $promote = $this->agents->last('db.pitr.promote', $target->id);
    expect($promote['payload']['stop'])->toBe($instance->id);
    $this->agents->succeed($promote['handle'], ['changed' => true]);

    $restore = Restore::query()->findOrFail($restoreId);
    $copy = DatabaseInstance::query()->findOrFail($restore->restored_instance_id);
    $done = $recovery->pitrProgress($restoreId);
    expect($done['state'])->toBe('succeeded')->and($done['message'])->toContain('latest point')
        ->and($copy->refresh()->pitr_enabled)->toBeTrue()->and($copy->name)->toBe($instance->name)->and($copy->server_id)->toBe($target->id)
        ->and(Database::query()->findOrFail($this->db->id)->database_instance_id)->toBe($copy->id)
        ->and($this->agents->last('db.pitr.base')['payload']['instance'])->toBe($copy->id)
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Retired);
});
