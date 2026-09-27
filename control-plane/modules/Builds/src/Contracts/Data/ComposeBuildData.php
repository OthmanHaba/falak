<?php

namespace Kiln\Builds\Contracts\Data;

use SensitiveParameter;

/**
 * Output of a compose build: the repository's compose file and the image built for each `build:` service.
 */
final readonly class ComposeBuildData
{
    /**
     * @param  string  $file  compose file path in the repository
     * @param  string  $content  its content (unrendered)
     * @param  array<string, string>  $images  service => pinned ref (repo@sha256:…)
     * @param  ?array{username: string, password: string, server?: string}  $registryAuth  pull credentials for the built-in registry
     */
    public function __construct(
        public string $file,
        public string $content,
        public array $images,
        #[SensitiveParameter] public ?array $registryAuth = null,
    ) {}
}
