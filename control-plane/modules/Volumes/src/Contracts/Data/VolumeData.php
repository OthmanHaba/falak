<?php

namespace Falak\Volumes\Contracts\Data;

use Falak\Volumes\Contracts\VolumeKind;

final readonly class VolumeData
{
    /**
     * @param  ?string  $serverId  null for shared paths (on every server of their site)
     * @param  string  $status  pending|active|failed|deleting
     * @param  list<AttachmentData>  $attachments
     * @param  bool  $sharedFile  a shared path that is one file (.env), not a directory
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public ?string $serverId,
        public string $name,
        public VolumeKind $kind,
        public ?string $dockerName,
        public ?string $hostPath,
        public ?int $sizeLimitBytes,
        public ?int $usedBytes,
        public bool $protected,
        public string $status,
        public array $attachments = [],
        public bool $sharedFile = false,
    ) {}
}
