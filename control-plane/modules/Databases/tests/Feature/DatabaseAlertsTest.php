<?php

use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Models\Alert;
use Falak\Databases\Application\Actions\PrunePitr;
use Falak\Databases\Application\Actions\TakePitrBase;
use Falak\Databases\Application\Jobs\CheckBackupHealth;
use Falak\Databases\Application\Jobs\MaintainPitr;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\Data\AgentInfo;
use Falak\Fleet\Events\AgentDatabasesReported;
use Falak\Identity\Contracts\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    databases_fake_dns();
    Carbon::setTestNow('2026-10-09 12:00:00');
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = databases_server($this->organization);
    [$this->db, , $this->engine] = databases_service($this->organization, 'postgresql', 'shop', $this->server);
    $this->provider = databases_provider($this->organization, ['verified_at' => now()]);
});

afterEach(fn () => Carbon::setTestNow());

function db_alerts(string $type, bool $recovery = false): Collection
{
    return Alert::query()->where('type', $type)->where('recovery', $recovery)->get();
}

function db_backup(object $test, BackupSchedule $schedule, string $finishedAt, BackupStatus $status = BackupStatus::Succeeded): Backup
{
    return Backup::query()->create([
        'organization_id' => $test->organization->id, 'schedule_id' => $schedule->id, 'database_id' => $test->db->id, 'database_instance_id' => $test->engine->id,
        'server_id' => $test->engine->server_id, 'server_name' => $test->engine->server_name, 'database_name' => 'shop', 'engine' => 'postgresql',
        'storage_provider_id' => $test->provider->id, 'object_key' => 'k/'.Str::random(6), 'compression' => 'zstd', 'trigger' => 'scheduled',
        'status' => $status, 'finished_at' => Carbon::parse($finishedAt),
    ]);
}

it('computes the interval between a schedule\'s runs', function () {
    $now = Carbon::now()->toImmutable();
    expect(CheckBackupHealth::interval('0 3 * * *', $now))->toBe(86400)
        ->and(CheckBackupHealth::interval('*/15 * * * *', $now))->toBe(900)
        ->and(CheckBackupHealth::interval('0 3 * * 0', $now))->toBe(7 * 86400);
});

it('alerts when no backup succeeded within twice the interval, and resolves on the next success', function () {
    $schedule = BackupSchedule::query()->create(['organization_id' => $this->organization->id, 'database_instance_id' => $this->engine->id, 'storage_provider_id' => $this->provider->id,
        'name' => 'Nightly', 'cron' => '0 3 * * *', 'enabled' => true]);
    $schedule->forceFill(['created_at' => now()->subDays(10)])->save();

    db_backup($this, $schedule, '2026-10-08 03:05:00');
    db_backup($this, $schedule, '2026-10-09 03:05:00', BackupStatus::Failed);
    dispatch_sync(new CheckBackupHealth);
    expect(db_alerts('databases.backup_missed'))->toHaveCount(0); // 33 hours < 48

    Carbon::setTestNow('2026-10-10 04:00:00'); // 49 hours since the last success
    dispatch_sync(new CheckBackupHealth);
    dispatch_sync(new CheckBackupHealth);

    $alert = db_alerts('databases.backup_missed')->sole();
    expect($alert->severity)->toBe(Severity::Critical)
        ->and($alert->title)->toContain('No backup of '.$this->engine->name)
        ->and($alert->url)->toBe(url('/databases/backups'))
        ->and($alert->action)->toBe('Review backups');

    db_backup($this, $schedule, '2026-10-10 04:01:00');
    dispatch_sync(new CheckBackupHealth);
    expect(db_alerts('databases.backup_missed', recovery: true))->toHaveCount(1);
});

it('counts a new schedule from its creation and ignores disabled ones', function () {
    $schedule = BackupSchedule::query()->create(['organization_id' => $this->organization->id, 'database_instance_id' => $this->engine->id, 'storage_provider_id' => $this->provider->id,
        'name' => 'Hourly', 'cron' => '0 * * * *', 'enabled' => true]);
    $schedule->forceFill(['created_at' => now()->subMinutes(90)])->save();
    dispatch_sync(new CheckBackupHealth);
    expect(db_alerts('databases.backup_missed'))->toHaveCount(0);

    $schedule->forceFill(['created_at' => now()->subHours(3), 'enabled' => false])->save();
    dispatch_sync(new CheckBackupHealth);
    expect(db_alerts('databases.backup_missed'))->toHaveCount(0);

    $schedule->forceFill(['enabled' => true])->save();
    dispatch_sync(new CheckBackupHealth);
    expect(db_alerts('databases.backup_missed')->sole()->title)->toContain('since the schedule was created');
});

it('alerts when a storage provider fails two probes in a row', function () {
    $fail = true;
    Http::fake(function () use (&$fail) {
        return $fail ? Http::response('<Error><Code>AccessDenied</Code></Error>', 403) : Http::response('', 200);
    });
    $unverified = databases_provider($this->organization, ['verified_at' => null]);

    dispatch_sync(new CheckBackupHealth(probe: true));
    expect(db_alerts('databases.storage_unreachable'))->toHaveCount(0);

    Carbon::setTestNow(now()->addMinutes(30));
    dispatch_sync(new CheckBackupHealth(probe: true));
    $alert = db_alerts('databases.storage_unreachable')->sole();
    expect($alert->title)->toBe("Storage {$this->provider->name} is unreachable")
        ->and($alert->url)->toBe(url('/settings/storage'))
        ->and($alert->body)->not->toContain('super-secret-access-key-value')
        ->and($alert->context['storage_provider_id'])->toBe($this->provider->id)
        ->and(Alert::query()->where('context->storage_provider_id', $unverified->id)->count())->toBe(0);

    $fail = false;
    dispatch_sync(new CheckBackupHealth(probe: true));
    expect(db_alerts('databases.storage_unreachable', recovery: true))->toHaveCount(1);
});

it('alerts on connections above 80% of the limit for five minutes', function () {
    $report = fn (int $used) => event(new AgentDatabasesReported('a', $this->organization->id, $this->server->id, [
        ['id' => $this->engine->id, 'state' => 'running', 'health' => 'healthy', 'secrets_missing' => false, 'connections' => ['used' => $used, 'max' => 100]],
    ]));

    $report(85);
    Carbon::setTestNow(now()->addMinutes(4));
    $report(90);
    expect(db_alerts('databases.connections_high'))->toHaveCount(0);

    Carbon::setTestNow(now()->addMinutes(2));
    $report(90);
    $report(95);
    $alert = db_alerts('databases.connections_high')->sole();
    expect($alert->title)->toBe("{$this->engine->name} on {$this->engine->server_name} uses 90 of 100 connections")
        ->and($alert->url)->toBe(url("/databases/instances/{$this->engine->id}"));

    $report(80); // exactly 80%: not above
    expect(db_alerts('databases.connections_high', recovery: true))->toHaveCount(1);

    // A spike shorter than the window never alerts.
    $report(99);
    Carbon::setTestNow(now()->addMinute());
    $report(10);
    expect(db_alerts('databases.connections_high'))->toHaveCount(1);
});

it('ignores connection figures of another organization\'s server', function () {
    [, $other] = memberOf();
    event(new AgentDatabasesReported('a', $other->id, $this->server->id, [
        ['id' => $this->engine->id, 'state' => 'running', 'health' => 'healthy', 'secrets_missing' => false, 'connections' => ['used' => 99, 'max' => 100]],
    ]));
    Carbon::setTestNow(now()->addMinutes(10));
    event(new AgentDatabasesReported('a', $other->id, $this->server->id, [
        ['id' => $this->engine->id, 'state' => 'running', 'health' => 'healthy', 'secrets_missing' => false, 'connections' => ['used' => 99, 'max' => 100]],
    ]));

    expect(Alert::query()->count())->toBe(0);
});

it('alerts when an instance with PITR on stops reporting its spool while its agent is online', function () {
    $online = true;
    app()->instance(AgentDirectory::class, new class($online) implements AgentDirectory
    {
        public function __construct(public bool &$online) {}

        public function forServer(string $serverId): ?AgentInfo
        {
            return $this->forServers([$serverId])[$serverId] ?? null;
        }

        public function forServers(array $serverIds): array
        {
            return collect($serverIds)->mapWithKeys(fn ($id) => [$id => new AgentInfo('a', $id, $this->online ? AgentStatus::Online : AgentStatus::Offline, 'v0.10.0', 'h', 'amd64', [], [], new DateTimeImmutable, null, null)])->all();
        }

        public function metrics(string $serverId, DateTimeInterface $since): array
        {
            return [];
        }
    });
    $this->engine->forceFill(['pitr_enabled' => true, 'pitr_storage_provider_id' => $this->provider->id, 'pitr_next_base_at' => now()->addDay(), 'health' => 'healthy',
        'pitr_report' => ['spool_bytes' => 0, 'volume_bytes' => 100, 'pending' => 0, 'at' => now()->subMinutes(5)->toIso8601String()]])->save();
    $maintain = fn () => (new MaintainPitr)->handle(app(TakePitrBase::class), app(PrunePitr::class), app(AgentDirectory::class));

    $maintain();
    expect(db_alerts('pitr.stopped'))->toHaveCount(0);

    Carbon::setTestNow(now()->addMinutes(6));
    $online = false; // the agent is offline: fleet.agent_offline covers it
    $maintain();
    expect(db_alerts('pitr.stopped'))->toHaveCount(0);

    $online = true;
    $maintain();
    expect(db_alerts('pitr.stopped')->sole()->severity)->toBe(Severity::Critical);

    event(new AgentDatabasesReported('a', $this->organization->id, $this->server->id, [
        ['id' => $this->engine->id, 'state' => 'running', 'health' => 'healthy', 'secrets_missing' => false, 'pitr' => ['spool_bytes' => 0, 'volume_bytes' => 100, 'pending' => 0]],
    ]));
    $maintain();
    expect(Alert::query()->where('type', 'pitr.recovered')->count())->toBe(1);
});
