<?php

namespace Falak\Builds\Contracts\Data;

use SensitiveParameter;

/**
 * An OCI image in the built-in registry (deploy.container.swap `image` + `registry_auth`).
 */
final readonly class ImageData
{
    /**
     * @param  string  $ref  pinned reference (repo@sha256:…) when the digest is known, else the pushed tag
     * @param  ?array{username: string, password: string, server?: string}  $registryAuth
     */
    public function __construct(
        public string $ref,
        #[SensitiveParameter] public ?array $registryAuth = null,
    ) {}
}
