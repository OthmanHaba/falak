<?php

namespace Falak\Builds\Contracts\Data;

final readonly class BuildRequest
{
    /**
     * @param  ?string  $commit  exact sha; when null the builder builds the head of $branch
     * @param  ?string  $branch  defaults to the site branch
     * @param  ?string  $deploymentId  opaque reference of the requesting deployment
     */
    public function __construct(
        public string $siteId,
        public ?string $commit = null,
        public ?string $branch = null,
        public ?string $deploymentId = null,
        public ?string $requestedBy = null,
        public bool $allowReuse = true,
    ) {}
}
