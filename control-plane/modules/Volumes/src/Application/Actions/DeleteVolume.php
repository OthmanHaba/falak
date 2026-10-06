<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Volume;
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
        if ($volume->protected) {
            throw ValidationException::withMessages(['volume' => "{$volume->name} is protected: turn protection off before deleting it."]);
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
}
