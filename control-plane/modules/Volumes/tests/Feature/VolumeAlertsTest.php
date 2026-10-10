<?php

use Falak\Alerting\Domain\Models\Alert;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\Role;
use Falak\Volumes\Application\Actions\RunVolumeBackup;
use Falak\Volumes\Application\Jobs\CheckVolumeBackups;
use Falak\Volumes\Application\Jobs\RunDueVolumeBackups;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = volumes_server($this->organization->id);
});

afterEach(fn () => Carbon::setTestNow());

function volume_alerts(string $type, bool $recovery = false): Collection
{
    return Alert::query()->where('type', $type)->where('recovery', $recovery)->orderBy('id')->get();
}

/** Runs the schedule (a day after the previous run) and settles its archive. */
function volume_backup_run(object $test, BackupSchedule $schedule, bool $succeed): void
{
    Carbon::setTestNow(now()->addDay());
    $schedule->forceFill(['next_run_at' => now()->subSecond()])->save();
    (new RunDueVolumeBackups)->handle(app(RunVolumeBackup::class), app(CurrentOrganization::class));
    $command = $test->agents->last('volume.archive');
    $succeed
        ? $test->agents->succeed($command['handle'], ['size_bytes' => 4096, 'sha256' => hash('sha256', 'x'), 'location' => 'https://b/x', 'plaintext_sha256' => hash('sha256', 'p'),
            'encryption' => $command['payload']['encryption']['mode'], 'key_id' => $command['payload']['encryption']['key_id']])
        : $test->agents->fail($command['handle'], 'tar: write error: no space left on device');
}

it('alerts on a failed volume backup once and resolves on the next success', function () {
    Carbon::setTestNow('2026-10-07 03:00:30');
    Http::fake();
    $volume = volumes_volume($this->organization->id, $this->server);
    $this->post("/volumes/{$volume->id}/schedules", ['storage_provider_id' => volumes_provider($this->organization->id)->id, 'cron' => '0 3 * * *'])->assertSessionHasNoErrors();
    $schedule = BackupSchedule::query()->sole();

    volume_backup_run($this, $schedule, true);
    expect(Alert::query()->where('type', 'like', 'volumes.backup_%')->count())->toBe(0);

    volume_backup_run($this, $schedule, false);
    volume_backup_run($this, $schedule, false);
    $failed = volume_alerts('volumes.backup_failed');
    expect($failed)->toHaveCount(2)
        ->and($failed->pluck('outcome')->map->value->all())->toBe(['delivered', 'deduplicated'])
        ->and($failed[0]->title)->toBe('Backup of volume data failed')
        ->and($failed[0]->body)->toContain('no space left')
        ->and($failed[0]->url)->toBe(url("/volumes/{$volume->id}"))
        ->and($failed[0]->action)->toBe('Review backups');

    volume_backup_run($this, $schedule, true);
    volume_backup_run($this, $schedule, true);
    expect(volume_alerts('volumes.backup_succeeded', recovery: true))->toHaveCount(1);
});

it('alerts when a volume schedule produced no backup within twice its interval', function () {
    Carbon::setTestNow('2026-10-07 12:00:00');
    $volume = volumes_volume($this->organization->id, $this->server);
    $schedule = BackupSchedule::query()->create(['organization_id' => $this->organization->id, 'volume_id' => $volume->id, 'storage_provider_id' => volumes_provider($this->organization->id)->id,
        'cron' => '0 3 * * *', 'enabled' => true]);
    $schedule->forceFill(['created_at' => now()->subHours(47)])->save();

    dispatch_sync(new CheckVolumeBackups);
    expect(volume_alerts('volumes.backup_missed'))->toHaveCount(0);

    Carbon::setTestNow('2026-10-07 13:30:00');
    dispatch_sync(new CheckVolumeBackups);
    dispatch_sync(new CheckVolumeBackups);
    expect(volume_alerts('volumes.backup_missed')->sole()->title)->toBe('No backup of volume data since its schedule was created');
});

it('resolves "almost full" once the volume is back below 85%, so the next crossing alerts again', function () {
    $volume = volumes_volume($this->organization->id, $this->server);
    $report = function (int $used) use ($volume) {
        $handle = $this->agents->dispatch($this->server->id, 'volume.inventory', ['volumes' => [$volume->ref()]]);
        $this->agents->succeed($handle, ['volumes' => [['id' => $volume->id, 'kind' => 'sized', 'exists' => true, 'used_bytes' => $used, 'size_bytes' => 10 * 1024 ** 3]]]);
    };

    $report(9 * 1024 ** 3);
    $report(2 * 1024 ** 3);
    $report(9 * 1024 ** 3);

    expect(volume_alerts('volumes.almost_full')->pluck('outcome')->map->value->all())->toBe(['delivered', 'delivered'])
        ->and(volume_alerts('volumes.almost_full', recovery: true)->sole()->title)->toBe('Volume data has room again (20% full)')
        ->and(volume_alerts('volumes.almost_full')->first()->action)->toBe('Grow volume');
});
