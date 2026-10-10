<?php

namespace Falak\Limits\Application;

/**
 * The service an agent's OOM / restart event is about.
 */
final readonly class ResolvedService
{
    /**
     * @param  string  $kind  site | compose_service | worker | daemon | database
     * @param  ?int  $memoryLimitMb  the memory limit it runs with (null = none)
     */
    public function __construct(
        public string $organizationId,
        public string $kind,
        public string $id,
        public ?string $siteId,
        public string $label,
        public string $url,
        public ?int $memoryLimitMb,
    ) {}
}
