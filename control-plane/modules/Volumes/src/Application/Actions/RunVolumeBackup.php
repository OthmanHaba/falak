<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\Exceptions\StorageUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\Consistency;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Snapshot a volume into object storage: the control plane presigns a PUT URL for a fresh object key in one of the
 * organization's storage providers (shared with database backups) and the agent streams `volume.archive` (tar | zstd)
 * to it. No storage credentials reach the server. Moves and clones to another server ship the data the same way.
 *
 * Step 4 (backup encryption) seals the stream on the server; until then the object is plaintext.
 */
final class RunVolumeBackup
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly BackupStorage $storage,
        private readonly ServerDirectory $servers,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  string  $trigger  manual|scheduled|move|clone
     *
     * @throws ValidationException (interactive triggers only; scheduled ones record a failed backup)
     */
    public function __invoke(
        Volume $volume,
        string $storageProviderId,
        Consistency $consistency = Consistency::None,
        string $trigger = 'manual',
        ?string $scheduleId = null,
        ?string $actorId = null,
    ): VolumeBackup {
        $background = $trigger === 'scheduled';
        $provider = $this->storage->find($volume->organization_id, $storageProviderId);

        $error = match (true) {
            ! $volume->kind->portable() => 'Only Docker and sized volumes can be backed up.',
            // Moves carry a database's data to its new server; other copies go through the database's own backups.
            $volume->holdsDatabase() && $trigger !== 'move' => 'A database’s data volume is backed up with the database.',
            $volume->status !== VolumeStatus::Active => "The volume is {$volume->status->value}.",
            $provider === null => 'Choose a storage provider of this organization.',
            default => null,
        };

        if ($error !== null && ! $background) {
            throw ValidationException::withMessages([$provider === null ? 'storage_provider_id' : 'volume' => $error]);
        }

        $backup = new VolumeBackup;
        $backup->id = strtolower((string) Str::ulid());
        $now = now()->utc();
        $server = $this->servers->find((string) $volume->server_id);

        $backup->forceFill([
            'organization_id' => $volume->organization_id,
            'volume_id' => $volume->id,
            'volume_name' => $volume->name,
            'volume_kind' => $volume->kind,
            'server_id' => $volume->server_id,
            'schedule_id' => $scheduleId,
            'storage_provider_id' => $provider?->id,
            'object_key' => $provider !== null ? $this->storage->key(
                $volume->organization_id,
                $provider->id,
                'volumes',
                Str::slug($server->name ?? 'server').'-'.substr((string) $volume->server_id, -6),
                $volume->name,
                $now->format('Y/m'),
                $now->format('Ymd\THis\Z').'-'.$backup->id.'.tar.zst',
            ) : '',
            'consistency' => $consistency,
            'trigger' => $trigger,
            'status' => BackupStatus::Pending,
            'volume_size_bytes' => $volume->size_limit_bytes,
            'requested_by' => $actorId,
        ]);

        if ($error !== null) {
            $backup->forceFill(['status' => BackupStatus::Failed, 'error' => $error, 'finished_at' => now()])->save();

            return $backup;
        }

        try {
            $url = $this->storage->presignPut($volume->organization_id, (string) $provider?->id, $backup->object_key);
        } catch (StorageUnavailable $e) {
            $backup->forceFill(['status' => BackupStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()])->save();

            return $backup;
        }

        $payload = [
            'volume' => $volume->ref(),
            'consistency' => $consistency->value,
            'destination' => ['kind' => 'presigned_url', 'url' => $url],
            // A move: the services mounting it stay stopped from this snapshot until they run on the target (no write
            // after the snapshot is lost). The move redeploys them, on the target or, when it fails, where they were.
            ...($trigger === 'move' ? ['keep_stopped' => true] : []),
        ];
        $key = "volume.archive:{$backup->id}";

        $handle = $background
            ? $this->commands->tryDispatch((string) $volume->server_id, 'volume.archive', $payload, $key)
            : $this->commands->dispatch((string) $volume->server_id, 'volume.archive', $payload, $key);

        if ($handle === null) {
            $backup->forceFill(['status' => BackupStatus::Failed, 'error' => AgentCommands::NOT_CONNECTED, 'finished_at' => now()])->save();

            return $backup;
        }

        $backup->forceFill(['command_id' => $handle->id, 'started_at' => now()])->save();

        $this->audit->record('volumes.backup_started', 'volume', $volume->id, [
            'name' => $volume->name,
            'backup_id' => $backup->id,
            'storage_provider' => $provider?->name,
            'trigger' => $trigger,
        ], $volume->organization_id);

        return $backup;
    }
}
