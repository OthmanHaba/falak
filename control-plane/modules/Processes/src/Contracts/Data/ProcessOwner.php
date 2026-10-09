<?php

namespace Falak\Processes\Contracts\Data;

/**
 * A program's or slice's owner: a worker or daemon, or the site itself (its web process, Octane, Horizon).
 */
final readonly class ProcessOwner
{
    /**
     * @param  string  $kind  worker | daemon | site
     * @param  string  $id  the worker's or daemon's id, or the site's
     * @param  ?int  $memoryLimitMb  the effective memory limit it runs with (null = none)
     */
    public function __construct(
        public string $organizationId,
        public string $siteId,
        public string $kind,
        public string $id,
        public string $label,
        public string $url,
        public ?int $memoryLimitMb,
    ) {}
}
