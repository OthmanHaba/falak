<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Volumes\Application\Redeployer;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Validation\ValidationException;

/**
 * Mount a volume into a docker site's container at a path; the site is redeployed so its container gets it. Compose
 * services mount what their compose file declares, classic sites their shared paths, databases their data volume.
 */
final class AttachVolume
{
    /** Container paths a volume never replaces. */
    private const RESERVED = ['/', '/bin', '/boot', '/dev', '/etc', '/lib', '/lib64', '/proc', '/run', '/run/secrets', '/sbin', '/sys', '/usr'];

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly Redeployer $redeployer,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Volume $volume, string $siteId, string $mountPath, bool $readOnly = false, ?string $actorId = null, bool $redeploy = true): Attachment
    {
        $site = $this->sites->find(strtolower($siteId));

        if ($site === null || $site->organizationId !== $volume->organization_id) {
            throw ValidationException::withMessages(['site_id' => 'Choose a service of this organization.']);
        }

        if ($site->runtime !== SiteRuntime::Docker) {
            throw ValidationException::withMessages(['site_id' => $site->runtime === SiteRuntime::Compose
                ? 'Compose services mount the volumes their compose file declares.'
                : 'Only container services can mount volumes; classic sites keep data in shared paths.']);
        }

        if (! $volume->kind->mountable() || $volume->composeKey() !== null) {
            throw ValidationException::withMessages(['volume' => 'This volume belongs to its service and cannot be attached elsewhere.']);
        }

        if ($volume->status === VolumeStatus::Deleting || $volume->status === VolumeStatus::Failed) {
            throw ValidationException::withMessages(['volume' => "The volume is {$volume->status->value}."]);
        }

        if ($volume->server_id === null || $site->target($volume->server_id) === null) {
            throw ValidationException::withMessages(['site_id' => "{$site->name} does not run on the volume’s server."]);
        }

        $mountPath = self::mountPath($mountPath);

        $taken = Attachment::query()
            ->where('attachable_type', AttachableType::Site)
            ->where('attachable_id', $site->id)
            ->get()
            ->contains(fn (Attachment $attachment) => $attachment->mount_path === $mountPath || $attachment->volume_id === $volume->id);

        if ($taken) {
            throw ValidationException::withMessages(['mount_path' => "{$site->name} already mounts this volume or something at {$mountPath}."]);
        }

        $attachment = $volume->attachments()->create([
            'attachable_type' => AttachableType::Site,
            'attachable_id' => $site->id,
            'mount_path' => $mountPath,
            'read_only' => $readOnly,
        ]);

        $this->audit->record('volumes.attached', 'volume', $volume->id, ['name' => $volume->name, 'site_id' => $site->id, 'mount_path' => $mountPath, 'read_only' => $readOnly], $volume->organization_id);

        if ($redeploy) {
            $this->redeployer->redeploy([$site->id], $actorId, "Volume {$volume->name} attached at {$mountPath}");
        }

        return $attachment;
    }

    /**
     * An absolute, normalized container path that is not a system directory.
     *
     * @throws ValidationException
     */
    public static function mountPath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        if (! str_starts_with($path, '/') || strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f:,]/', $path) === 1
            || array_intersect($segments, ['', '.', '..']) !== []) {
            throw ValidationException::withMessages(['mount_path' => 'Use an absolute path without ".", ".." or ":" (e.g. /data).']);
        }

        $path = '/'.implode('/', $segments);

        if (in_array($path, self::RESERVED, true)) {
            throw ValidationException::withMessages(['mount_path' => "{$path} is a system directory of the container."]);
        }

        return $path;
    }
}
