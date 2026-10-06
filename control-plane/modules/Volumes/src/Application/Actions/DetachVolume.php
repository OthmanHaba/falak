<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Application\Redeployer;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Models\Attachment;
use Illuminate\Validation\ValidationException;

/**
 * Unmount a volume from a site and redeploy it; the volume and its data stay. A shared path detached from its site
 * stops being shared (its volume goes, the files stay on the servers).
 */
final class DetachVolume
{
    public function __construct(
        private readonly Redeployer $redeployer,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Attachment $attachment, ?string $actorId = null, bool $redeploy = true): void
    {
        $volume = $attachment->volume;

        if ($attachment->attachable_type === AttachableType::ComposeService) {
            throw ValidationException::withMessages(['attachment' => 'Compose services mount the volumes their compose file declares: change the file instead.']);
        }

        if ($attachment->attachable_type === AttachableType::Database) {
            throw ValidationException::withMessages(['attachment' => 'A database keeps its data volume.']);
        }

        $attachment->delete();

        if ($volume->kind === VolumeKind::SharedPath) {
            $volume->delete();
        }

        $this->audit->record('volumes.detached', 'volume', $volume->id, ['name' => $volume->name, 'site_id' => $attachment->attachable_id, 'mount_path' => $attachment->mount_path], $volume->organization_id);

        if ($redeploy) {
            $this->redeployer->redeploy([$attachment->attachable_id], $actorId, "Volume {$volume->name} detached from {$attachment->mount_path}");
        }
    }
}
