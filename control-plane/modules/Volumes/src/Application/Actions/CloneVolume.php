<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\Consistency;
use Falak\Volumes\Domain\Enums\OperationKind;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copy a volume into a new one: on the same server with volume.clone, on another server through object storage
 * (volume.archive, then volume.restore there; the transfer backup is removed afterwards). Previews use it too.
 */
final class CloneVolume
{
    public function __construct(
        private readonly CreateVolume $create,
        private readonly RunVolumeBackup $backup,
        private readonly AgentCommands $commands,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  ?string  $storageProviderId  required for another server
     *
     * @throws ValidationException
     */
    public function __invoke(Volume $source, string $serverId, string $name, Consistency $consistency = Consistency::None, ?string $storageProviderId = null, ?string $actorId = null): Operation
    {
        if (! $source->kind->portable() || $source->status !== VolumeStatus::Active) {
            throw ValidationException::withMessages(['volume' => 'Only active Docker and sized volumes can be cloned.']);
        }

        if ($source->holdsDatabase()) {
            throw ValidationException::withMessages(['volume' => 'A database’s data volume is copied through the database’s backups.']);
        }

        $local = $serverId === $source->server_id;

        if (! $local && $storageProviderId === null) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Choose the storage that carries the data to the other server.']);
        }

        $target = $this->create->prepare($source->organization_id, $serverId, $name, $source->kind, $source->size_limit_bytes, labels: (array) $source->labels, actorId: $actorId);

        $operation = DB::transaction(function () use ($target, $source, $actorId) {
            CreateVolume::save($target);

            return Operation::query()->create([
                'organization_id' => $source->organization_id,
                'volume_id' => $target->id,
                'kind' => OperationKind::Clone,
                'status' => OperationStatus::Running,
                'meta' => ['source_id' => $source->id, 'source_name' => $source->name],
                'requested_by' => $actorId,
            ]);
        });

        try {
            $local ? $this->local($operation, $source, $target, $consistency) : $this->remote($operation, $source, $target, $consistency, (string) $storageProviderId, $actorId);
        } catch (ValidationException $e) {
            $operation->delete();
            $target->delete();

            throw $e;
        }

        $this->audit->record('volumes.clone_started', 'volume', $source->id, ['name' => $source->name, 'target' => $target->name, 'server_id' => $serverId], $source->organization_id);

        return $operation;
    }

    private function local(Operation $operation, Volume $source, Volume $target, Consistency $consistency): void
    {
        $payload = array_filter([
            'source' => $source->ref(),
            'target' => $target->ref(),
            'size_bytes' => $target->size_limit_bytes,
            'labels' => AgentCommands::labels($target),
            'consistency' => $consistency->value,
        ], fn ($v) => $v !== null);

        $handle = $this->commands->dispatch((string) $source->server_id, 'volume.clone', $payload, "volume.clone:{$operation->id}");
        $target->forceFill(['command_id' => $handle->id])->save();
        $operation->forceFill(['step' => 'clone', 'command_id' => $handle->id])->save();
    }

    /**
     * Archive now; HandleCommandOutcome restores on the target server when the archive is in storage.
     */
    private function remote(Operation $operation, Volume $source, Volume $target, Consistency $consistency, string $storageProviderId, ?string $actorId): void
    {
        $backup = ($this->backup)($source, $storageProviderId, $consistency, 'clone', actorId: $actorId);

        if ($backup->status === BackupStatus::Failed) {
            throw ValidationException::withMessages(['volume' => (string) $backup->error]);
        }

        $operation->forceFill(['step' => 'archive', 'command_id' => $backup->command_id, 'meta' => [...(array) $operation->meta, 'backup_id' => $backup->id]])->save();
    }
}
