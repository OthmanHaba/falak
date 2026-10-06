<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Validation\ValidationException;

/**
 * A site (or compose stack) is deleted: its attachments go, its shared paths stop being volumes (the files stay on the
 * servers, like the site's directory), and only the volumes the user picked are deleted, once the service's containers
 * stopped mounting them. Protected volumes never are.
 */
final class ReleaseSite
{
    public function __construct(private readonly DeleteVolume $delete) {}

    /**
     * @param  list<string>  $deleteVolumeIds
     *
     * @throws ValidationException
     */
    public function __invoke(string $siteId, array $deleteVolumeIds = [], ?string $actorId = null): void
    {
        $attachments = Attachment::query()
            ->with('volume')
            ->whereIn('attachable_type', [AttachableType::Site, AttachableType::ComposeService])
            ->where('attachable_id', $siteId)
            ->get();

        $volumes = $attachments->pluck('volume')->unique('id')->keyBy('id');
        // Compose volumes stay attached to nothing once their stack is gone: still the site's to delete.
        $volumes = $volumes->merge(Volume::query()->where('options->compose->site_id', $siteId)->get()->keyBy('id'));
        $delete = array_values(array_unique(array_map('strtolower', $deleteVolumeIds)));

        foreach ($delete as $id) {
            $volume = $volumes->get($id);

            if ($volume === null) {
                throw ValidationException::withMessages(['delete_volumes' => 'Choose volumes of this service.']);
            }

            if ($volume->protected) {
                throw ValidationException::withMessages(['delete_volumes' => "{$volume->name} is protected and is never deleted with its service."]);
            }
        }

        Attachment::query()->whereIn('id', $attachments->modelKeys())->delete();

        foreach ($volumes as $volume) {
            /** @var Volume $volume */
            if ($volume->kind === VolumeKind::SharedPath) {
                $volume->delete();
            } elseif (in_array($volume->id, $delete, true) && ! $volume->attachments()->exists()) {
                ($this->delete)($volume, $actorId, (int) config('volumes.delete_wait_s', 120), background: true);
            }
        }
    }
}
