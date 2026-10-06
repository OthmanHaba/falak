<?php

namespace Falak\Volumes\Contracts\Data;

use Falak\Volumes\Contracts\AttachableType;

final readonly class AttachmentData
{
    public function __construct(
        public string $id,
        public string $volumeId,
        public AttachableType $type,
        public string $attachableId,
        public ?string $service,
        public string $mountPath,
        public bool $readOnly,
    ) {}
}
