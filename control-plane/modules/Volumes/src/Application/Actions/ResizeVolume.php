<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\OperationKind;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Validation\ValidationException;

/**
 * Grow a sized volume online (volume.resize: fallocate, losetup -c, resize2fs). Shrinking is refused: ext4 cannot
 * shrink while mounted, and data would not fit back. The new limit is recorded when the agent confirms it.
 */
final class ResizeVolume
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Volume $volume, int $sizeBytes, ?string $actorId = null): Operation
    {
        if ($volume->kind !== VolumeKind::Sized) {
            throw ValidationException::withMessages(['size_bytes' => 'Only sized volumes have a size to change.']);
        }

        if ($volume->status !== VolumeStatus::Active) {
            throw ValidationException::withMessages(['size_bytes' => "The volume is {$volume->status->value}."]);
        }

        if ($sizeBytes <= (int) $volume->size_limit_bytes) {
            throw ValidationException::withMessages(['size_bytes' => 'Volumes only grow: choose a size larger than the current one.']);
        }

        if ($sizeBytes > (int) config('volumes.max_size_bytes')) {
            throw ValidationException::withMessages(['size_bytes' => 'A sized volume holds at most 16 TiB.']);
        }

        if (Operation::query()->where('volume_id', $volume->id)->where('kind', OperationKind::Resize)->whereIn('status', [OperationStatus::Pending, OperationStatus::Running])->exists()) {
            throw ValidationException::withMessages(['size_bytes' => 'The volume is already being resized.']);
        }

        $operation = Operation::query()->create([
            'organization_id' => $volume->organization_id,
            'volume_id' => $volume->id,
            'kind' => OperationKind::Resize,
            'status' => OperationStatus::Running,
            'meta' => ['from' => $volume->size_limit_bytes, 'to' => $sizeBytes],
            'requested_by' => $actorId,
        ]);

        try {
            $handle = $this->commands->dispatch((string) $volume->server_id, 'volume.resize', ['volume' => $volume->ref(), 'size_bytes' => $sizeBytes], "volume.resize:{$operation->id}", 'size_bytes');
        } catch (ValidationException $e) {
            $operation->delete();

            throw $e;
        }

        $operation->forceFill(['command_id' => $handle->id])->save();

        $this->audit->record('volumes.resized', 'volume', $volume->id, ['name' => $volume->name, 'from' => $volume->size_limit_bytes, 'to' => $sizeBytes], $volume->organization_id);

        return $operation;
    }
}
