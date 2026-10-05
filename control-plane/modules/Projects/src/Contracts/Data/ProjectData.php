<?php

namespace Falak\Projects\Contracts\Data;

final readonly class ProjectData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public ?string $description,
        public ?string $icon,
        /** The organization's fallback project: services created without a placement land here. */
        public bool $isDefault,
    ) {}
}
