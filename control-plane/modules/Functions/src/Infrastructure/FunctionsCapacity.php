<?php

namespace Falak\Functions\Infrastructure;

use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Limits\Contracts\CapacitySource;
use Falak\Limits\Contracts\Data\CapacityItem;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;

/**
 * Functions on a server: each instance is a container with the function's memory and CPU limits; at most
 * max_instances run at once, so that is what the function may take. Idle functions scale to zero: nothing is reserved.
 */
final class FunctionsCapacity implements CapacitySource
{
    public function __construct(private readonly SiteDirectory $sites) {}

    public function onServer(string $organizationId, string $serverId): array
    {
        $sites = [];

        foreach ($this->sites->forServer($serverId) as $site) {
            if ($site->organizationId === $organizationId && $site->runtime === SiteRuntime::Function) {
                $sites[$site->id] = $site;
            }
        }

        $items = [];

        foreach (CloudFunction::query()->where('organization_id', $organizationId)->whereIn('site_id', array_keys($sites))->get() as $function) {
            $instances = max(1, $function->min_instances, $function->max_instances);
            $items[] = new CapacityItem('function', $function->site_id, $sites[$function->site_id]->name, $function->memory_mb * $instances, null,
                round($function->cpus * $instances, 2), "/sites/{$function->site_id}");
        }

        return $items;
    }
}
