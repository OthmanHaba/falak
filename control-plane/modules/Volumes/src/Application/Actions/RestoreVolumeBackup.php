<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Security\BackupKeys;
use Falak\Volumes\Application\Transfers;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\OperationKind;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restore a backup into a NEW volume (never over live data). With $swap, the services of the backed-up volume are
 * switched to the restored one and redeployed once it is ready; the old volume stays until someone deletes it.
 */
final class RestoreVolumeBackup
{
    public function __construct(
        private readonly CreateVolume $create,
        private readonly Transfers $transfers,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(VolumeBackup $backup, string $serverId, string $name, ?int $sizeBytes = null, bool $swap = false, ?string $actorId = null, #[\SensitiveParameter] ?string $identity = null): Operation
    {
        if (! $backup->restorable()) {
            throw ValidationException::withMessages(['backup' => 'Only successful, encrypted backups whose storage provider still exists can be restored.']);
        }

        if ($backup->isCustomerHeld() && ! BackupKeys::validIdentity(trim((string) $identity))) {
            throw ValidationException::withMessages(['identity' => 'This backup\'s key is customer-held: paste the age identity (AGE-SECRET-KEY-1…) that matches its recipient.']);
        }

        $source = $backup->volume_id !== null ? Volume::query()->find($backup->volume_id) : null;

        if ($source !== null && $source->holdsDatabase()) {
            throw ValidationException::withMessages(['backup' => 'A database’s data volume is restored with the database.']);
        }

        if ($swap && ($source === null || $source->server_id !== $serverId)) {
            throw ValidationException::withMessages(['swap' => 'Swapping needs the backed-up volume, and a restore on its server.']);
        }

        $size = $backup->volume_kind === VolumeKind::Sized ? self::size($backup, $sizeBytes) : null;
        $target = $this->create->prepare($backup->organization_id, $serverId, $name, $backup->volume_kind, $size, labels: (array) ($source?->labels ?? []), actorId: $actorId);

        $operation = DB::transaction(function () use ($target, $backup, $source, $swap, $actorId) {
            CreateVolume::save($target);

            return Operation::query()->create([
                'organization_id' => $backup->organization_id,
                'volume_id' => $target->id,
                'kind' => OperationKind::Restore,
                'status' => OperationStatus::Running,
                'meta' => array_filter(['backup_id' => $backup->id, 'swap_from' => $swap ? $source?->id : null]),
                'requested_by' => $actorId,
            ]);
        });

        try {
            $this->transfers->restore($operation, $backup, $target, identity: $identity);
        } catch (ValidationException $e) {
            $operation->delete();
            $target->delete();

            throw $e;
        }

        $this->audit->record('volumes.restore_started', 'volume', $target->id, ['name' => $target->name, 'backup_id' => $backup->id, 'from' => $backup->volume_name, 'swap' => $swap], $backup->organization_id);

        return $operation;
    }

    /** A sized volume large enough for the snapshot: the backed-up limit, the asked size, or a fifth over its data. */
    private static function size(VolumeBackup $backup, ?int $asked): int
    {
        $needed = (int) ceil(((int) $backup->uncompressed_bytes) * 1.2);

        return max((int) config('volumes.min_size_bytes'), (int) $backup->volume_size_bytes, (int) $asked, $needed);
    }
}
