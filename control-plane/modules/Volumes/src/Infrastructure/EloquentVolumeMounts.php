<?php

namespace Falak\Volumes\Infrastructure;

use Falak\Sites\Contracts\Data\SharedPath;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\Data\Mount;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Contracts\VolumeMounts;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;

final class EloquentVolumeMounts implements VolumeMounts
{
    public function forSite(string $siteId, string $serverId): array
    {
        return Attachment::query()
            ->with('volume')
            ->where('attachable_type', AttachableType::Site)
            ->where('attachable_id', strtolower($siteId))
            ->whereHas('volume', fn ($q) => $q->where('server_id', $serverId)->whereIn('status', [VolumeStatus::Active, VolumeStatus::Pending]))
            ->orderBy('mount_path')
            ->get()
            ->filter(fn (Attachment $attachment) => $attachment->volume->kind->mountable())
            ->map(fn (Attachment $attachment) => new Mount($attachment->volume_id, $attachment->volume->mountSource(), $attachment->mount_path, $attachment->read_only))
            ->values()
            ->all();
    }

    public function sharedPaths(string $siteId): array
    {
        return Attachment::query()
            ->with('volume')
            ->where('attachable_type', AttachableType::Site)
            ->where('attachable_id', strtolower($siteId))
            ->whereHas('volume', fn ($q) => $q->where('kind', VolumeKind::SharedPath))
            ->get()
            ->sortBy(fn (Attachment $attachment) => (int) ($attachment->volume->options['position'] ?? 0))
            ->map(fn (Attachment $attachment) => new SharedPath($attachment->mount_path, $attachment->volume->sharedFile() ? 'file' : 'directory'))
            ->values()
            ->all();
    }
}
