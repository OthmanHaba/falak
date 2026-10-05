<?php

namespace Falak\Edge\Application;

use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Infrastructure\RouteCompiler;

/**
 * Schedules a (debounced) re-apply on every server whose config depends on a site.
 */
final class EdgeChanges
{
    public function __construct(
        private readonly RouteCompiler $compiler,
        private readonly EdgeRoutes $routes,
    ) {}

    /**
     * @param  list<string>  $extraServerIds
     */
    public function siteChanged(string $siteId, array $extraServerIds = []): void
    {
        $this->routes->schedule(...array_values(array_unique([...$this->compiler->serversForSite($siteId), ...$extraServerIds])));
    }

    /**
     * @return list<string>
     */
    public function serversFor(string $siteId): array
    {
        return $this->compiler->serversForSite($siteId);
    }
}
