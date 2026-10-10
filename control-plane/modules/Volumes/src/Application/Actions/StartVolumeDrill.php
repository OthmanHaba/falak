<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\DrillStatus;
use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Security\BackupKeys;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Falak\Volumes\Domain\Models\VolumeDrill;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Starts a volume restore drill (volume.drill): the schedule's latest archive is restored into a scratch directory on
 * the volume's server (or the schedule's drill server), checked against what it recorded, and removed. Customer-held
 * archives can't be drilled (the key never reaches Falak): recorded as skipped.
 */
final class StartVolumeDrill
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly BackupStorage $storage,
        private readonly BackupKeys $keys,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException (manual drills only: no agent connected)
     */
    public function __invoke(BackupSchedule $schedule, bool $manual = false): VolumeDrill
    {
        $schedule->loadMissing('volume');
        $backup = VolumeBackup::query()->where('schedule_id', $schedule->id)->where('status', BackupStatus::Succeeded)
            ->latest('finished_at')->orderByDesc('id')->first();
        $serverId = $schedule->drill_server_id ?? $schedule->volume?->server_id;

        $drill = new VolumeDrill;
        $drill->id = strtolower((string) Str::ulid());
        $drill->forceFill([
            'organization_id' => $schedule->organization_id,
            'schedule_id' => $schedule->id,
            'backup_id' => $backup?->id,
            'volume_id' => $schedule->volume_id,
            'volume_name' => $schedule->volume->name ?? $backup?->volume_name,
            'server_id' => $serverId,
            'status' => DrillStatus::Pending,
        ]);

        $skip = match (true) {
            $backup === null || ! $backup->restorable() => 'No restorable archive of this schedule yet.',
            $backup->isCustomerHeld() => 'The archives\' keys are customer-held: Falak cannot restore them to check.',
            $serverId === null => 'The volume has no server.',
            default => null,
        };

        if ($skip !== null) {
            $drill->forceFill(['status' => DrillStatus::Skipped, 'reason' => $skip, 'finished_at' => now()])->save();

            return $drill;
        }

        try {
            $encryption = $this->keys->opening(BackupKeys::CP, $backup->wrapped_key, $backup->organization_id, $backup->id);
            $url = $this->storage->presignGet($backup->organization_id, (string) $backup->storage_provider_id, $backup->object_key);
        } catch (Throwable $e) {
            $drill->forceFill(['status' => DrillStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()])->save();
            SettleVolumeDrill::alert($drill);

            return $drill;
        }

        $drill->save();
        $payload = [
            'drill' => $drill->id,
            'source' => ['kind' => 'url', 'url' => $url],
            'sha256' => $backup->sha256,
            ...array_filter([
                'plaintext_sha256' => $backup->plaintext_sha256,
                'archive_bytes' => $backup->size_bytes,
                'uncompressed_bytes' => $backup->uncompressed_bytes,
            ], fn ($value) => $value !== null),
            'encryption' => $encryption,
            'checks' => array_filter([
                'files' => $backup->files,
                'tolerance_percent' => (float) config('volumes.drills.tolerance_percent', 10),
            ], fn ($value) => $value !== null),
        ];
        $key = "volume.drill:{$drill->id}";

        $handle = $manual
            ? $this->commands->dispatch((string) $serverId, 'volume.drill', $payload, $key, 'schedule')
            : $this->commands->tryDispatch((string) $serverId, 'volume.drill', $payload, $key);

        if ($handle === null) {
            $drill->forceFill(['status' => DrillStatus::Skipped, 'reason' => AgentCommands::NOT_CONNECTED, 'finished_at' => now()])->save();

            return $drill;
        }

        $drill->forceFill(['command_id' => $handle->id, 'started_at' => now()])->save();

        $this->audit->record('volumes.drill_started', 'volume', (string) $schedule->volume_id, [
            'drill_id' => $drill->id,
            'backup_id' => $backup->id,
            'server_id' => $serverId,
            'manual' => $manual,
        ], $schedule->organization_id);

        return $drill;
    }
}
