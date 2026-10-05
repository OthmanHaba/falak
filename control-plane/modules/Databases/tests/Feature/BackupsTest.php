<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Falak\Databases\Application\Actions\RunBackupSchedule;
use Falak\Databases\Application\Jobs\RunDueBackups;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Events\BackupFailed;
use Falak\Databases\Events\BackupSucceeded;
use Falak\Databases\Events\RestoreFinished;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\Role;
use Falak\Servers\Contracts\ServerType;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    databases_fake_dns();
    Carbon::setTestNow('2026-09-27 02:59:30');
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->engine = databases_engine($this->organization, 'mysql', ServerType::Database);
    $this->db = databases_active_db($this->engine, 'shop');
    $this->provider = databases_provider($this->organization);
});

afterEach(fn () => Carbon::setTestNow());

function backups_result(string $content = 'dump'): array
{
    return ['size_bytes' => strlen($content) * 1000, 'sha256' => hash('sha256', $content), 'location' => 's3://falak-backups/x', 'duration_ms' => 4200];
}

it('dispatches db.backup with a presigned PUT URL and never the credentials', function () {
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id])->assertSessionHasNoErrors();

    $backup = Backup::query()->firstOrFail();
    $command = $this->agents->last('db.backup');
    $url = $command['payload']['destination']['url'];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(databases_schema_errors($command))->toBe([])
        ->and($command['payload'])->toMatchArray(['engine' => 'mysql', 'database' => 'shop', 'compression' => 'gzip'])
        ->and($command['payload']['destination']['kind'])->toBe('presigned_url')
        ->and($command['handle']->idempotencyKey)->toBe("db.backup:{$backup->id}")
        ->and($backup->object_key)->toMatch('#^acme/'.preg_quote(Str::slug($this->engine->server_name), '#').'-[a-z0-9]{6}/shop/2026/09/20260927T025930Z-'.$backup->id.'\.sql\.gz$#')
        ->and($url)->toStartWith('https://falak-backups.s3.eu-central-1.amazonaws.com/'.$backup->object_key.'?')
        ->and($query)->toMatchArray(['X-Amz-Algorithm' => 'AWS4-HMAC-SHA256', 'X-Amz-Expires' => '43200', 'X-Amz-SignedHeaders' => 'host'])
        ->and($query['X-Amz-Signature'])->toMatch('/^[a-f0-9]{64}$/')
        ->and(json_encode($command['payload']))->not->toContain('super-secret-access-key-value')
        ->and($backup->status)->toBe(BackupStatus::Pending)
        ->and($backup->trigger)->toBe('manual');
});

it('records size, checksum and duration and announces BackupSucceeded', function () {
    Event::fake([BackupSucceeded::class]);
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id, 'compression' => 'none']);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], backups_result());

    $backup = Backup::query()->firstOrFail();
    expect($backup)
        ->status->toBe(BackupStatus::Succeeded)
        ->size_bytes->toBe(4000)
        ->sha256->toBe(hash('sha256', 'dump'))
        ->duration_ms->toBe(4200)
        ->object_key->toEndWith('.sql')
        ->and($backup->isRestorable())->toBeTrue();

    Event::assertDispatched(BackupSucceeded::class, fn ($e) => $e->backupId === $backup->id && $e->sizeBytes === 4000 && $e->databaseName === 'shop' && $e->trigger === 'manual');
});

it('announces BackupFailed on agent failures and missing checksums', function () {
    Event::fake([BackupFailed::class]);

    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->fail($this->agents->last('db.backup')['handle'], 'mysqldump: Got error: 1045', 2);

    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], ['size_bytes' => 1, 'location' => 'x']);

    $failed = Backup::query()->orderBy('id')->get();
    expect($failed->pluck('status')->all())->toBe([BackupStatus::Failed, BackupStatus::Failed])
        ->and($failed[0]->error)->toContain('1045')
        ->and($failed[1]->error)->toContain('checksum');
    Event::assertDispatchedTimes(BackupFailed::class, 2);
});

it('rejects manual backups of inactive databases and foreign providers', function () {
    $pending = $this->engine->databases()->create(['organization_id' => $this->organization->id, 'server_id' => $this->engine->server_id, 'name' => 'wip', 'status' => ResourceStatus::Pending]);
    [, $other] = memberOf();
    $foreign = databases_provider($other);

    $this->post("/databases/databases/{$pending->id}/backups", ['storage_provider_id' => $this->provider->id])->assertSessionHasErrors('database');
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $foreign->id])->assertNotFound();
    $this->agents->assertNothingDispatched('db.backup');
});

it('validates schedules and computes the next run in UTC', function () {
    $this->post("/databases/servers/{$this->engine->id}/schedules", ['name' => 'Bad', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => 'every day'])
        ->assertSessionHasErrors('cron');

    $this->post("/databases/servers/{$this->engine->id}/schedules", [
        'name' => 'Nightly', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *', 'retention_count' => 7,
    ])->assertSessionHasNoErrors();

    $schedule = BackupSchedule::query()->firstOrFail();
    expect($schedule->next_run_at->toIso8601String())->toBe('2026-09-27T03:00:00+00:00')
        ->and($schedule->databases->pluck('name')->all())->toBe(['shop']);
});

it('runs due schedules exactly once and advances next_run_at', function () {
    $this->post("/databases/servers/{$this->engine->id}/schedules", ['name' => 'Nightly', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *']);

    (new RunDueBackups)->handle(app(RunBackupSchedule::class), app(CurrentOrganization::class));
    expect(Backup::query()->count())->toBe(0);

    Carbon::setTestNow('2026-09-27 03:00:10');
    dispatch_sync(new RunDueBackups);
    dispatch_sync(new RunDueBackups);

    $schedule = BackupSchedule::query()->firstOrFail();
    expect(Backup::query()->where('trigger', 'scheduled')->count())->toBe(1)
        ->and($schedule->next_run_at->toIso8601String())->toBe('2026-09-28T03:00:00+00:00')
        ->and($schedule->last_run_at)->not->toBeNull()
        ->and(count($this->agents->dispatched('db.backup')))->toBe(1);
});

it('marks scheduled backups failed when the agent is offline', function () {
    Event::fake([BackupFailed::class]);
    $this->agents->unavailable($this->engine->server_id);
    $this->post("/databases/servers/{$this->engine->id}/schedules", ['name' => 'Nightly', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *']);

    Carbon::setTestNow('2026-09-27 03:00:10');
    dispatch_sync(new RunDueBackups);

    expect(Backup::query()->first())->status->toBe(BackupStatus::Failed)->trigger->toBe('scheduled')->error->toContain('not connected');
    Event::assertDispatched(BackupFailed::class, fn ($e) => $e->trigger === 'scheduled' && $e->scheduleId !== null);
});

it('runs a schedule manually, recorded as manual, and reports a missing agent', function () {
    $this->post("/databases/servers/{$this->engine->id}/schedules", ['name' => 'Nightly', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *']);
    $scheduleId = BackupSchedule::query()->value('id');

    $this->post("/databases/schedules/{$scheduleId}/run")->assertSessionHasNoErrors();
    expect(Backup::query()->first())->trigger->toBe('manual')->schedule_id->toBe($scheduleId);

    $this->agents->unavailable($this->engine->server_id);
    $this->post("/databases/schedules/{$scheduleId}/run")->assertSessionHasErrors('database');
});

it('prunes by retention count and age with signed DELETEs, always keeping the newest', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $this->post("/databases/servers/{$this->engine->id}/schedules", ['name' => 'Nightly', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *', 'retention_count' => 2, 'retention_days' => 30]);
    $schedule = BackupSchedule::query()->firstOrFail();

    $make = function (string $when, string $key) use ($schedule) {
        $backup = Backup::query()->create([
            'organization_id' => $this->organization->id, 'schedule_id' => $schedule->id, 'database_id' => $this->db->id, 'database_server_id' => $this->engine->id,
            'server_id' => $this->engine->server_id, 'server_name' => $this->engine->server_name, 'database_name' => 'shop', 'engine' => 'mysql',
            'storage_provider_id' => $this->provider->id, 'object_key' => $key, 'compression' => 'gzip', 'trigger' => 'scheduled',
            'status' => BackupStatus::Succeeded, 'sha256' => str_repeat('b', 64), 'size_bytes' => 10,
        ]);
        $backup->forceFill(['created_at' => Carbon::parse($when)])->save();

        return $backup;
    };

    $oldest = $make('2026-07-01', 'acme/k1.sql.gz');
    $third = $make('2026-09-20', 'acme/k2.sql.gz');
    $second = $make('2026-09-25', 'acme/k3.sql.gz');
    $newest = $make('2026-09-26', 'acme/k4.sql.gz');

    // A new successful scheduled backup triggers pruning.
    $this->post("/databases/schedules/{$schedule->id}/run");
    $this->agents->succeed($this->agents->last('db.backup')['handle'], backups_result());

    expect($oldest->refresh()->status)->toBe(BackupStatus::Pruned)
        ->and($third->refresh()->status)->toBe(BackupStatus::Pruned)
        ->and($second->refresh()->status)->toBe(BackupStatus::Pruned)
        ->and($newest->refresh()->status)->toBe(BackupStatus::Succeeded)
        ->and(Backup::query()->where('status', BackupStatus::Succeeded)->count())->toBe(2);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://falak-backups.s3.eu-central-1.amazonaws.com/acme/k1.sql.gz'
        && str_starts_with($r->header('Authorization')[0], 'AWS4-HMAC-SHA256'));
});

it('keeps rows when a prune DELETE fails and retries next time', function () {
    Http::fake(['*' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403)]);
    $this->post("/databases/servers/{$this->engine->id}/schedules", ['name' => 'Nightly', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *', 'retention_count' => 1]);
    $schedule = BackupSchedule::query()->firstOrFail();

    $this->post("/databases/schedules/{$schedule->id}/run");
    $this->agents->succeed($this->agents->last('db.backup')['handle'], backups_result('a'));
    Carbon::setTestNow(now()->addMinute());
    $this->post("/databases/schedules/{$schedule->id}/run");
    $this->agents->succeed($this->agents->last('db.backup')['handle'], backups_result('b'));

    $old = Backup::query()->orderBy('created_at')->orderBy('id')->first();
    expect($old->status)->toBe(BackupStatus::Succeeded)->and($old->prune_error)->toContain('AccessDenied');
});

it('restores with confirmation through a presigned GET and checksum', function () {
    Event::fake([RestoreFinished::class]);
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], backups_result());
    $backup = Backup::query()->firstOrFail();

    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $this->engine->id, 'database' => 'shop_restored', 'confirm' => 'shop'])
        ->assertSessionHasErrors('confirm');

    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $this->engine->id, 'database' => 'shop_restored', 'confirm' => 'shop_restored'])
        ->assertSessionHasNoErrors();

    $command = $this->agents->last('db.restore');
    $restore = Restore::query()->firstOrFail();

    expect(databases_schema_errors($command))->toBe([])
        ->and($command['payload'])->toMatchArray(['engine' => 'mysql', 'database' => 'shop_restored', 'compression' => 'gzip', 'sha256' => hash('sha256', 'dump')])
        ->and($command['payload']['source']['kind'])->toBe('url')
        ->and($command['payload']['source']['url'])->toStartWith("https://falak-backups.s3.eu-central-1.amazonaws.com/{$backup->object_key}?")
        ->and($command['payload']['source']['url'])->toContain('X-Amz-Expires=21600')
        ->and($command['handle']->idempotencyKey)->toBe("db.restore:{$restore->id}");

    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $this->engine->id, 'database' => 'shop_restored', 'confirm' => 'shop_restored'])
        ->assertSessionHasErrors('database');

    $this->agents->succeed($command['handle'], ['bytes' => 123456, 'duration_ms' => 900]);

    expect($restore->refresh())->status->toBe(RestoreStatus::Succeeded)->bytes->toBe(123456)
        ->and(Database::query()->where('name', 'shop_restored')->first()?->status)->toBe(ResourceStatus::Active);
    Event::assertDispatched(RestoreFinished::class, fn ($e) => $e->succeeded && $e->databaseName === 'shop_restored');
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'databases.restore_requested', 'subject_id' => $backup->id]);
});

it('only lets admins restore and refuses cross-engine restores', function () {
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], backups_result());
    $backup = Backup::query()->firstOrFail();
    $pg = databases_engine($this->organization, 'postgresql');

    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $pg->id, 'database' => 'shop', 'confirm' => 'shop'])
        ->assertSessionHasErrors('database_server_id');

    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer)->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $this->engine->id, 'database' => 'shop', 'confirm' => 'shop'])
        ->assertForbidden();

    $this->agents->assertNothingDispatched('db.restore');
});

it('records failed restores', function () {
    Event::fake([RestoreFinished::class]);
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $this->agents->succeed($this->agents->last('db.backup')['handle'], backups_result());
    $backup = Backup::query()->firstOrFail();

    $this->post("/databases/backups/{$backup->id}/restore", ['database_server_id' => $this->engine->id, 'database' => 'shop', 'confirm' => 'shop']);
    $this->agents->fail($this->agents->last('db.restore')['handle'], 'sha256 mismatch');

    expect(Restore::query()->first())->status->toBe(RestoreStatus::Failed)->error->toBe('sha256 mismatch');
    Event::assertDispatched(RestoreFinished::class, fn ($e) => ! $e->succeeded);
});

it('deletes a backup and its object', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $backup = Backup::query()->firstOrFail();

    $this->delete("/databases/backups/{$backup->id}")->assertSessionHasErrors('backup');

    $this->agents->succeed($this->agents->last('db.backup')['handle'], backups_result());
    $this->delete("/databases/backups/{$backup->id}")->assertSessionHasNoErrors();

    expect(Backup::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), $backup->object_key));
});

it('lists backup history across the organization', function () {
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id]);
    [, $other] = memberOf();
    $otherEngine = databases_engine($other, 'mysql');
    Backup::query()->create([
        'organization_id' => $other->id, 'server_id' => $otherEngine->server_id, 'server_name' => 'x', 'database_name' => 'x', 'engine' => 'mysql',
        'object_key' => 'x', 'compression' => 'gzip', 'trigger' => 'manual', 'status' => BackupStatus::Failed,
    ]);

    $this->get('/databases/backups')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Databases/Backups', false)
        ->has('backups.data', 1)
        ->where('backups.data.0.database_name', 'shop'));
});
