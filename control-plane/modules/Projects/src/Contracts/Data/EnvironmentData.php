<?php

namespace Falak\Projects\Contracts\Data;

final readonly class EnvironmentData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $projectId,
        public string $name,
        /** Unique per project; used in URLs (/projects/{project}/{slug}). */
        public string $slug,
        public bool $isProduction,
        public ?string $forkedFromId,
        /** A pull request's preview (Previews) */
        public bool $isPreview = false,
        /** A preview of a pull request from a fork: untrusted, no secrets */
        public bool $isForkPreview = false,
    ) {}
}
