<?php

use Falak\Databases\Application\Actions\StartDrill;
use Falak\Databases\Application\Jobs\RunDueDrills;
use Falak\Databases\Contracts\DrillFrequency;
use Falak\Databases\Contracts\DrillStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Drill;
use Falak\Databases\Events\DrillFinished;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Projects\Contracts\ProjectDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    databases_fake_dns();
    Carbon::setTestNow('2026-10-07 02:59:30');
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->engine = databases_instance($this->organization, 'postgresql');
    $this->db = databases_active_db($this->engine, 'shop');
    $this->provider = databases_provider($this->organization);
});

afterEach(fn () => Carbon::setTestNow());

/** Instances of environment $id are in a production environment or not. */
function drills_environment(object $test, string $id, bool $production): void
{
    $test->engine->forceFill(['environment_id' => $id])->save();
    $projects = Mockery::mock(ProjectDirectory::class);
    $projects->shouldReceive('environment')->with($id)
        ->andReturn(new EnvironmentData($id, $test->organization->id, strtolower((string) Str::ulid()), 'env', 'env', $production, null));
    app()->instance(ProjectDirectory::class, $projects);
}

function drills_schedule(object $test, array $attributes = []): BackupSchedule
{
    $test->post("/databases/instances/{$test->engine->id}/schedules", [
        'name' => 'Nightly', 'storage_provider_id' => $test->provider->id, 'database_ids' => [$test->db->id], 'cron' => '0 3 * * *', ...$attributes,
    ])->assertSessionHasNoErrors();

    return BackupSchedule::query()->latest('id')->firstOrFail();
}

/** A scheduled backup that succeeded, with row counts. */
function drills_backup(object $test, BackupSchedule $schedule): Backup
{
    $test->post("/databases/schedules/{$schedule->id}/run")->assertSessionHasNoErrors();
    $command = $test->agents->last('db.backup');
    $test->agents->succeed($command['handle'], [
        'size_bytes' => 100, 'sha256' => str_repeat('a', 64), 'location' => 'x', 'plaintext_sha256' => str_repeat('b', 64), 'uncompressed_bytes' => 4000,
        'encryption' => $command['payload']['encryption']['mode'], 'key_id' => $command['payload']['encryption']['key_id'], 'cipher' => 'aes-256-gcm', 'compression' => 'zstd',
        'table_counts' => ['public.orders' => 1000, 'public.users' => 10],
    ]);

    return Backup::query()->latest('id')->firstOrFail();
}

it('drills weekly by default in production environments, and not elsewhere', function () {
    drills_environment($this, strtolower((string) Str::ulid()), true);
    $schedule = drills_schedule($this);
    expect($schedule->drill)->toBe(DrillFrequency::Weekly)
        ->and($schedule->next_drill_at?->toIso8601String())->toBe('2026-10-07T04:00:00+00:00');

    drills_environment($this, strtolower((string) Str::ulid()), false);
    $staging = drills_schedule($this, ['name' => 'Staging']);
    expect($staging->drill)->toBe(DrillFrequency::Off)->and($staging->next_drill_at)->toBeNull();

    // An explicit choice wins, and is kept on later saves that don't mention it.
    $this->put("/databases/schedules/{$staging->id}", ['name' => 'Staging', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *', 'drill' => 'monthly'])
        ->assertSessionHasNoErrors();
    $this->put("/databases/schedules/{$staging->id}", ['name' => 'Staging 2', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *'])
        ->assertSessionHasNoErrors();
    expect($staging->refresh()->drill)->toBe(DrillFrequency::Monthly);
});

it('validates the check query, the frequency and the drill server', function () {
    $base = ['name' => 'N', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *', 'drill' => 'weekly'];
    foreach (['DELETE FROM orders', 'SELECT 1; DROP TABLE orders', 'SELECT 1 -- x', 'SELECT 1) q; COMMIT; DROP TABLE orders; SELECT (1', 'SELECT \'\\! sh\''] as $query) {
        $this->post("/databases/instances/{$this->engine->id}/schedules", [...$base, 'drill_query' => $query])->assertSessionHasErrors('drill_query');
    }
    $this->post("/databases/instances/{$this->engine->id}/schedules", [...$base, 'drill' => 'daily'])->assertSessionHasErrors('drill');
    [, $other] = memberOf();
    $foreign = databases_server($other);
    $this->post("/databases/instances/{$this->engine->id}/schedules", [...$base, 'drill_server_id' => $foreign->id])->assertSessionHasErrors('drill_server_id');

    $mine = databases_server($this->organization);
    $schedule = drills_schedule($this, [...$base, 'drill_query' => "SELECT id FROM orders WHERE status = 'paid';", 'drill_server_id' => $mine->id]);
    expect($schedule)->drill_query->toBe("SELECT id FROM orders WHERE status = 'paid'")->drill_server_id->toBe($mine->id);
});

it('starts due drills once, restores the least recently verified backup on a throwaway instance, and records the result', function () {
    $schedule = drills_schedule($this, ['drill' => 'weekly', 'drill_query' => 'SELECT 1 FROM orders']);
    $backup = drills_backup($this, $schedule);
    $schedule->forceFill(['next_drill_at' => now()->subMinute()])->save();

    (new RunDueDrills)->handle(app(StartDrill::class), app(CurrentOrganization::class));
    (new RunDueDrills)->handle(app(StartDrill::class), app(CurrentOrganization::class));

    expect($this->agents->dispatched('db.drill'))->toHaveCount(1);
    $command = $this->agents->last('db.drill');
    $drill = Drill::query()->sole();
    expect(databases_schema_errors($command))->toBe([])
        ->and($command['handle']->serverId)->toBe($this->engine->server_id)
        ->and($command['payload'])->toMatchArray(['drill' => $drill->id, 'database' => 'shop', 'sha256' => $backup->sha256, 'plaintext_sha256' => $backup->plaintext_sha256])
        ->and($command['payload']['instance'])->toMatchArray(['engine' => 'postgres', 'digest' => $this->engine->image_digest, 'memory_bytes' => 512 * 1024 ** 2])
        ->and($command['payload']['encryption'])->toMatchArray(['mode' => 'cp', 'key_id' => $backup->id])
        ->and($command['payload']['checks'])->toEqual(['table_counts' => ['public.orders' => 1000, 'public.users' => 10], 'tolerance_percent' => 10.0, 'query' => 'SELECT 1 FROM orders'])
        ->and($schedule->refresh()->next_drill_at?->toIso8601String())->toBe('2026-10-14T02:59:30+00:00');

    $this->agents->succeed($command['handle'], ['status' => 'passed', 'checks' => [['name' => 'restore', 'passed' => true, 'detail' => 'ok']],
        'download_ms' => 1500, 'restore_ms' => 2600, 'duration_ms' => 9000, 'tables' => 2]);

    expect($drill->refresh())->status->toBe(DrillStatus::Passed)->rto_estimate_seconds->toBe(5)->duration_ms->toBe(9000)
        ->and($backup->refresh()->verified_at)->not->toBeNull()
        ->and($backup->drill_status)->toBe('passed')
        ->and($this->agents->commands[$command['handle']->id]['payload']['encryption']['key'])->toBe('[forgotten]');

    // The schedule page shows the history and the verified badge.
    $this->get("/databases/instances/{$this->engine->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('schedules.0.drills.0.status', 'passed')
        ->where('schedules.0.drills.0.rto_estimate_seconds', 5)
        ->where('backups.0.verified_at', fn ($at) => $at !== null)
        ->where('backups.0.encryption_mode', 'cp'));
});

it('alerts when a drill fails and when drills pass again', function () {
    Event::fake([DrillFinished::class]);
    $schedule = drills_schedule($this, ['drill' => 'weekly']);
    drills_backup($this, $schedule);

    $this->post("/databases/schedules/{$schedule->id}/drill")->assertSessionHasNoErrors();
    $this->agents->succeed($this->agents->last('db.drill')['handle'], ['status' => 'failed', 'checks' => [
        ['name' => 'restore', 'passed' => true], ['name' => 'row_counts', 'passed' => false, 'detail' => 'public.orders: 500 rows, 1000 when backed up'],
    ], 'download_ms' => 1, 'restore_ms' => 1, 'duration_ms' => 2, 'tables' => 2]);

    $failed = Drill::query()->sole();
    expect($failed)->status->toBe(DrillStatus::Failed)->error->toContain('row_counts: public.orders')
        ->and(Backup::query()->sole()->verified_at)->toBeNull();
    Event::assertDispatched(DrillFinished::class, fn ($e) => ! $e->passed && $e->scheduleId === $schedule->id && str_contains($e->detail, 'public.orders')
        && $e->toAlert()->type === 'databases.drill_failed');

    // An agent error is a failure too.
    $this->post("/databases/schedules/{$schedule->id}/drill");
    $this->agents->fail($this->agents->last('db.drill')['handle'], 'docker: no space left');
    expect(Drill::query()->latest('id')->first())->status->toBe(DrillStatus::Failed)->error->toBe('docker: no space left');

    $this->post("/databases/schedules/{$schedule->id}/drill");
    $this->agents->succeed($this->agents->last('db.drill')['handle'], ['status' => 'passed', 'checks' => [], 'download_ms' => 1, 'restore_ms' => 1, 'duration_ms' => 2, 'tables' => 2]);
    Event::assertDispatched(DrillFinished::class, fn ($e) => $e->passed && $e->toAlert()->resolves);
});

it('records skipped drills: no room on the server, customer-held keys, no backup yet', function () {
    $schedule = drills_schedule($this, ['drill' => 'weekly']);
    $this->post("/databases/schedules/{$schedule->id}/drill");
    expect(Drill::query()->sole())->status->toBe(DrillStatus::Skipped)->reason->toContain('No successful backup');

    drills_backup($this, $schedule);
    $this->post("/databases/schedules/{$schedule->id}/drill");
    $this->agents->succeed($this->agents->last('db.drill')['handle'], ['status' => 'skipped', 'reason' => '300 MiB of memory available, the drill needs 768 MiB', 'checks' => [],
        'download_ms' => 0, 'restore_ms' => 0, 'duration_ms' => 1, 'tables' => 0]);
    expect(Drill::query()->latest('id')->first())->status->toBe(DrillStatus::Skipped)->reason->toContain('drill server');

    // With a drill server, drills run there.
    $other = databases_server($this->organization);
    $this->put("/databases/schedules/{$schedule->id}", ['name' => 'Nightly', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *', 'drill_server_id' => $other->id]);
    $this->post("/databases/schedules/{$schedule->id}/drill");
    expect($this->agents->last('db.drill')['handle']->serverId)->toBe($other->id);

    $customer = drills_schedule($this, ['name' => 'Customer', 'drill' => 'weekly', 'encryption_mode' => 'customer', 'age_recipient' => 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p']);
    drills_backup($this, $customer);
    $count = count($this->agents->dispatched('db.drill'));
    $this->post("/databases/schedules/{$customer->id}/drill");
    expect(count($this->agents->dispatched('db.drill')))->toBe($count)
        ->and(Drill::query()->where('schedule_id', $customer->id)->sole())->status->toBe(DrillStatus::Skipped)->reason->toContain('customer-held');
});

it('keeps drills of other organizations apart', function () {
    $schedule = drills_schedule($this, ['drill' => 'weekly']);
    [$stranger] = memberOf();
    $this->actingAs($stranger)->post("/databases/schedules/{$schedule->id}/drill")->assertNotFound();
    expect(Drill::query()->count())->toBe(0);
});
