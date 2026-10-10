<?php

namespace Falak\Sites\Infrastructure;

use Falak\Limits\Contracts\CapacitySource;
use Falak\Limits\Contracts\Data\CapacityItem;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;

/**
 * Sites on a server, with their limits: a Docker site's container, each service of a compose project, a
 * classic site's slice (PHP-FPM, Octane, the web process). FrankenPHP and static sites run in the shared edge (no
 * process of their own); functions are the Functions module's.
 */
final class SitesCapacity implements CapacitySource
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ComposeSites $compose,
        private readonly ComposeInspector $inspector,
    ) {}

    public function onServer(string $organizationId, string $serverId): array
    {
        $items = [];

        foreach ($this->sites->forServer($serverId) as $site) {
            if ($site->organizationId !== $organizationId || in_array($site->runtime, [SiteRuntime::FrankenPhp, SiteRuntime::Static, SiteRuntime::Function], true)) {
                continue;
            }

            if ($site->runtime === SiteRuntime::Compose) {
                foreach ($this->inspector->parse($this->compose->project($site->id) ?? '')->serviceNames() as $service) {
                    $limits = $site->composeServiceLimits($service);
                    $items[] = self::item('compose_service', "{$site->id}:{$service}", "{$site->name} · {$service}", $limits, $site);
                }

                continue;
            }

            $items[] = self::item('site', $site->id, $site->name, $site->limits, $site);
        }

        return $items;
    }

    private static function item(string $kind, string $id, string $name, ResourceLimits $limits, SiteData $site): CapacityItem
    {
        return new CapacityItem($kind, $id, $name, $limits->memoryLimit, $limits->memoryReservation, $limits->cpus, "/sites/{$site->id}");
    }
}
