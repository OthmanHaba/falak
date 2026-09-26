<?php

namespace Kiln\SourceControl\Contracts\Data;

final readonly class RepositoryData
{
    /**
     * @param  string  $fullName  provider path, e.g. "acme/shop" (GitLab may include subgroups)
     */
    public function __construct(
        public string $fullName,
        public string $defaultBranch,
        public bool $private,
        public string $sshUrl,
        public string $httpsUrl,
        public ?string $webUrl = null,
    ) {}
}
