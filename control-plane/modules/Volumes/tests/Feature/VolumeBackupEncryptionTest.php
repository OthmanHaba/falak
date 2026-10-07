<?php

use Falak\Databases\Contracts\DrillFrequency;
use Falak\Databases\Contracts\DrillStatus;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Security\BackupKeys;
use Falak\Volumes\Application\Actions\RunVolumeBackup;
use Falak\Volumes\Application\Actions\StartVolumeDrill;
use Falak\Volumes\Application\Jobs\RunDueVolumeDrills;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Falak\Volumes\Domain\Models\VolumeDrill;
use Falak\Volumes\Events\VolumeDrillFinished;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 02:59:30');
    Http::fake();
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = volumes_server($this->organization->id);
    $this->provider = volumes_provider($this->organization->id);
    $this->volume = volumes_volume($this->organization->id, $this->server);
});

afterEach(fn () => Carbon::setTestNow());

/** A scheduled archive that succeeded; returns the backup and the raw key it was sent with (cp). */
function venc_archive(object $test, BackupSchedule $schedule): array
{
    $test->post("/volumes/{$test->volume->id}/backups", ['storage_provider_id' => $test->provider->id])->assertSessionHasNoErrors();
    VolumeBackup::query()->latest('id')->firstOrFail()->forceFill(['schedule_id' => $schedule->id])->save();
    // Scheduled runs carry the schedule's key mode: run one the way the job does.
    app(RunVolumeBackup::class)($test->volume, $test->provider->id, $schedule->consistency, 'scheduled', $schedule->id);
    $command = $test->agents->last('volume.archive');
    expect(volumes_schema_errors($command))->toBe([]);
    $key = $command['payload']['encryption']['key'] ?? null;
    $test->agents->succeed($command['handle'], ['size_bytes' => 4096, 'sha256' => str_repeat('a', 64), 'location' => 'x', 'uncompressed_bytes' => 10240, 'files' => 12,
        'plaintext_sha256' => str_repeat('b', 64), 'encryption' => $command['payload']['encryption']['mode'], 'key_id' => $command['payload']['encryption']['key_id'],
        'cipher' => 'aes-256-gcm', 'compression' => 'zstd']);

    return [VolumeBackup::query()->where('id', $command['payload']['encryption']['key_id'])->sole(), $key];
}

function venc_schedule(object $test, array $attributes = []): BackupSchedule
{
    $test->post("/volumes/{$test->volume->id}/schedules", ['storage_provider_id' => $test->provider->id, 'cron' => '0 3 * * *', ...$attributes])->assertSessionHasNoErrors();

    return BackupSchedule::query()->latest('id')->firstOrFail();
}

it('encrypts archives with a per-backup key sealed for the organization, forgotten in the payload', function () {
    $schedule = venc_schedule($this);
    [$backup, $key] = venc_archive($this, $schedule);
    $command = $this->agents->last('volume.archive');

    expect($backup)->encryption_mode->toBe('cp')->files->toBe(12)->plaintext_sha256->toBe(str_repeat('b', 64))
        ->and($backup->object_key)->toEndWith('.tar.zst.fkb')
        ->and(app(BackupKeys::class)->unwrap($backup->wrapped_key, $this->organization->id, $backup->id))->toBe(base64_decode($key, true))
        ->and($this->agents->commands[$command['handle']->id]['payload']['encryption']['key'])->toBe('[forgotten]');

    // Restores carry the unwrapped key and the plaintext checksum.
    $this->post("/volumes/backups/{$backup->id}/restore", ['server_id' => $this->server->id, 'name' => 'restored'])->assertSessionHasNoErrors();
    $restore = $this->agents->last('volume.restore');
    expect($restore['payload']['encryption'])->toBe(['mode' => 'cp', 'key_id' => $backup->id, 'key' => $key])
        ->and($restore['payload']['plaintext_sha256'])->toBe(str_repeat('b', 64))
        ->and(volumes_schema_errors($restore))->toBe([]);
});

it('uses the customer recipient and needs the identity to restore', function () {
    $recipient = 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p';
    $this->post("/volumes/{$this->volume->id}/schedules", ['storage_provider_id' => $this->provider->id, 'cron' => '0 3 * * *', 'encryption_mode' => 'customer'])
        ->assertSessionHasErrors('age_recipient');
    $schedule = venc_schedule($this, ['encryption_mode' => 'customer', 'age_recipient' => $recipient]);
    [$backup] = venc_archive($this, $schedule);

    expect($this->agents->last('volume.archive')['payload']['encryption'])->toBe(['mode' => 'age', 'key_id' => $backup->id, 'recipient' => $recipient])
        ->and($backup)->wrapped_key->toBeNull()->encryption_mode->toBe('customer');

    $this->post("/volumes/backups/{$backup->id}/restore", ['server_id' => $this->server->id, 'name' => 'restored'])->assertSessionHasErrors('identity');
    $identity = 'AGE-SECRET-KEY-1'.str_repeat('Q', 58);
    $this->post("/volumes/backups/{$backup->id}/restore", ['server_id' => $this->server->id, 'name' => 'restored', 'identity' => $identity])->assertSessionHasNoErrors();
    $restore = $this->agents->last('volume.restore');
    expect($restore['payload']['encryption']['identity'])->toBe($identity);
    $this->agents->succeed($restore['handle'], ['bytes' => 10, 'files' => 12]);
    expect($this->agents->commands[$restore['handle']->id]['payload']['encryption']['identity'])->toBe('[forgotten]');

    $this->withSession(['identity.reauthenticated_at' => time()])->postJson("/volumes/backups/{$backup->id}/key")->assertUnprocessable();
});

it('exports an archive key after re-authentication, audited', function () {
    [$backup, $key] = venc_archive($this, venc_schedule($this));

    $this->postJson("/volumes/backups/{$backup->id}/key")->assertStatus(423);
    $response = $this->withSession(['identity.reauthenticated_at' => time()])->postJson("/volumes/backups/{$backup->id}/key")->assertOk();
    expect($response->json('content'))->toContain(bin2hex(base64_decode($key, true)));
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'volumes.backup_key_exported']);

    [$stranger] = memberOf();
    $this->actingAs($stranger)->withSession(['identity.reauthenticated_at' => time()])->postJson("/volumes/backups/{$backup->id}/key")->assertNotFound();
});

it('drills volume schedules and alerts on failure', function () {
    Event::fake([VolumeDrillFinished::class]);
    $schedule = venc_schedule($this, ['drill' => 'weekly']);
    expect($schedule->drill)->toBe(DrillFrequency::Weekly)->and($schedule->next_drill_at)->not->toBeNull();
    [$backup] = venc_archive($this, $schedule);
    $schedule->forceFill(['next_drill_at' => now()->subMinute()])->save();

    (new RunDueVolumeDrills)->handle(app(StartVolumeDrill::class), app(CurrentOrganization::class));
    (new RunDueVolumeDrills)->handle(app(StartVolumeDrill::class), app(CurrentOrganization::class));

    expect($this->agents->dispatched('volume.drill'))->toHaveCount(1);
    $command = $this->agents->last('volume.drill');
    expect(volumes_schema_errors($command))->toBe([])
        ->and($command['payload'])->toMatchArray(['sha256' => $backup->sha256, 'plaintext_sha256' => $backup->plaintext_sha256])
        ->and($command['payload']['checks'])->toEqual(['files' => 12, 'tolerance_percent' => 10]);

    $this->agents->succeed($command['handle'], ['status' => 'failed', 'checks' => [['name' => 'files', 'passed' => false, 'detail' => '3 files, 12 in the snapshot']],
        'download_ms' => 1, 'restore_ms' => 1, 'duration_ms' => 3, 'files' => 3, 'bytes' => 10]);

    expect(VolumeDrill::query()->sole())->status->toBe(DrillStatus::Failed)->error->toContain('files: 3 files');
    Event::assertDispatched(VolumeDrillFinished::class, fn ($e) => ! $e->passed && $e->toAlert()->type === 'volumes.drill_failed');

    $this->post("/volumes/schedules/{$schedule->id}/drill")->assertSessionHasNoErrors();
    $this->agents->succeed($this->agents->last('volume.drill')['handle'], ['status' => 'passed', 'checks' => [], 'download_ms' => 1000, 'restore_ms' => 1000, 'duration_ms' => 3, 'files' => 12, 'bytes' => 10]);
    expect($backup->refresh()->verified_at)->not->toBeNull()
        ->and(VolumeDrill::query()->latest('id')->first()->rto_estimate_seconds)->toBe(2);
});
