<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Illuminate\Validation\ValidationException;

/**
 * Delete a volume and its data. Protected volumes never are; attached ones are detached first (or released with their
 * service). Docker and sized volumes are removed on their server by volume.delete and the row goes when it succeeds;
 * host paths (bind), shared paths and compose `external` volumes are only forgotten: Falak does not own their files.
 */
final class DeleteVolume
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  int  $waitSeconds  wait for containers still mounting it to go (a service deleted just before)
     *
     * @throws ValidationException
     */
    public function __invoke(Volume $volume, ?string $actorId = null, int $waitSeconds = 0, bool $background = false): void
    {
        if (($refusal = $this->refusal($volume)) !== null) {
            throw ValidationException::withMessages(['volume' => $refusal]);
        }

        if ($volume->attachments()->exists()) {
            throw ValidationException::withMessages(['volume' => "{$volume->name} is attached: detach it first."]);
        }

        if ($volume->status === VolumeStatus::Deleting) {
            return;
        }

        $this->audit->record('volumes.deleted', 'volume', $volume->id, ['name' => $volume->name, 'kind' => $volume->kind->value, 'server_id' => $volume->server_id], $volume->organization_id);

        if (! in_array($volume->kind, [VolumeKind::Docker, VolumeKind::Sized], true) || $volume->external() || $volume->server_id === null) {
            $volume->delete();

            return;
        }

        $payload = ['volume' => $volume->ref(), 'wait_s' => max(0, min(600, $waitSeconds))];
        $key = "volume.delete:{$volume->id}";

        $handle = $background
            ? $this->commands->tryDispatch($volume->server_id, 'volume.delete', $payload, $key)
            : $this->commands->dispatch($volume->server_id, 'volume.delete', $payload, $key);

        $volume->forceFill($handle !== null
            ? ['status' => VolumeStatus::Deleting, 'status_message' => null, 'command_id' => $handle->id]
            : ['status_message' => 'Not deleted: '.AgentCommands::NOT_CONNECTED])->save();
    }

    /**
     * Why the volume cannot be deleted now, if it cannot: protected, busy (a backup, clone, move or restore reads or
     * fills it), or its Docker volume is also another row's (a compose `name:` pointing at it: deleting it would delete
     * that one's data; the pointing row is external and only forgotten).
     */
    public function refusal(Volume $volume): ?string
    {
        if ($volume->protected) {
            return "{$volume->name} is protected: turn protection off before deleting it.";
        }

        $busy = Operation::query()->whereIn('status', [OperationStatus::Pending, OperationStatus::Running])
            ->where(fn ($q) => $q->where('volume_id', $volume->id)->orWhere('meta->source_id', $volume->id)->orWhere('meta->swap_from', $volume->id))->exists()
            || VolumeBackup::query()->where('volume_id', $volume->id)->where('status', BackupStatus::Pending)->exists();

        if ($busy) {
            return "{$volume->name} is busy (a backup, restore, clone or move is running): try again when it finished.";
        }

        $shared = $volume->kind === VolumeKind::Docker && ! $volume->external() && $volume->docker_name !== null && Volume::query()->where('server_id', $volume->server_id)
            ->where('docker_name', $volume->docker_name)->whereKeyNot($volume->id)->exists();

        return $shared ? "Another volume of this server is the same Docker volume ({$volume->docker_name}): remove that one first." : null;
    }
}
