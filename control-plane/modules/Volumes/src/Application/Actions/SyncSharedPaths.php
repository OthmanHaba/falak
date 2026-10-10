<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SharedPath;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Facades\DB;

/**
 * A classic site's shared paths are volumes of kind shared_path, one per path, attached to the site at the path
 * (relative to the release). They have no server: deploy.prepare creates and links them on every server of the site,
 * under /srv/falak/sites/<slug>/shared/<path>.
 */
final class SyncSharedPaths
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<SharedPath>  $paths
     */
    public function __invoke(string $organizationId, string $siteId, array $paths, ?string $actorId = null): void
    {
        $site = $this->sites->find($siteId);

        if ($site === null || $site->organizationId !== $organizationId) {
            return;
        }

        $wanted = [];

        foreach ($paths as $path) {
            $wanted[$path->path] ??= $path->type === 'file' ? 'file' : 'directory';
        }

        // The order the user listed them in (deploy.prepare links them in it).
        $positions = array_flip(array_map('strval', array_keys($wanted)));

        DB::transaction(function () use ($site, $organizationId, $wanted, $positions, $actorId) {
            $current = Attachment::query()
                ->with('volume')
                ->where('attachable_type', AttachableType::Site)
                ->where('attachable_id', $site->id)
                ->whereHas('volume', fn ($q) => $q->where('kind', VolumeKind::SharedPath))
                ->lockForUpdate()
                ->get()
                ->keyBy('mount_path');

            foreach ($current as $mountPath => $attachment) {
                /** @var Attachment $attachment */
                if (! array_key_exists((string) $mountPath, $wanted)) {
                    $attachment->volume->delete();
                } else {
                    $attachment->volume->forceFill(['options' => ['type' => $wanted[$mountPath], 'position' => $positions[(string) $mountPath]]])->save();
                }
            }

            foreach ($wanted as $path => $type) {
                if ($current->has($path)) {
                    continue;
                }

                $volume = Volume::query()->create([
                    'organization_id' => $organizationId,
                    'server_id' => null,
                    'name' => mb_substr("{$site->slug}/{$path}", 0, 128),
                    'kind' => VolumeKind::SharedPath,
                    'host_path' => $site->sharedPath().'/'.$path,
                    'options' => ['type' => $type, 'position' => $positions[(string) $path]],
                    'status' => VolumeStatus::Active,
                    'created_by' => $actorId,
                ]);

                $volume->attachments()->create([
                    'attachable_type' => AttachableType::Site,
                    'attachable_id' => $site->id,
                    'mount_path' => (string) $path,
                    'read_only' => false,
                ]);
            }
        });

        $this->audit->record('volumes.shared_paths_synced', 'site', $site->id, ['paths' => array_keys($wanted)], $organizationId);
    }
}
