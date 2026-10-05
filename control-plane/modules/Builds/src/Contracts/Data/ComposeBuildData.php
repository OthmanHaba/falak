<?php

namespace Falak\Builds\Contracts\Data;

use SensitiveParameter;

/**
 * Output of a compose build: the repository's compose project and the image built for each `build:` service.
 */
final readonly class ComposeBuildData
{
    /**
     * @param  string  $file  (first) compose file path in the repository
     * @param  string  $content  the project: merged files with root-relative paths (newer builders), else the file as is
     * @param  array<string, string>  $images  service => pinned ref (repo@sha256:…)
     * @param  ?array{username: string, password: string, server?: string}  $registryAuth  pull credentials for the built-in registry
     * @param  ?list<array{path: string, content: string, mode: int}>  $assets  repository files the project mounts (base64);
     *                                                                          null for builders that don't merge projects
     * @param  list<string>  $missing  referenced paths the repository doesn't have
     */
    public function __construct(
        public string $file,
        public string $content,
        public array $images,
        #[SensitiveParameter] public ?array $registryAuth = null,
        public ?array $assets = null,
        public array $missing = [],
    ) {}

    /**
     * Repository paths shipped with the release (null: the builder doesn't merge projects).
     *
     * @return ?list<string>
     */
    public function repoFiles(): ?array
    {
        return $this->assets === null ? null : array_values(array_map(fn (array $asset) => $asset['path'], $this->assets));
    }
}
