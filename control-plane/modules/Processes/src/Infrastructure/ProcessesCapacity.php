<?php

namespace Falak\Processes\Infrastructure;

use Falak\Limits\Contracts\CapacitySource;
use Falak\Limits\Contracts\Data\CapacityItem;
use Falak\Limits\Contracts\LimitDefaults;
use Falak\Processes\Application\StatusPoller;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\Worker;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;

/**
 * Workers and daemons on a server: one slice each (its instances share the limit).
 */
final class ProcessesCapacity implements CapacitySource
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly LimitDefaults $defaults,
    ) {}

    public function onServer(string $organizationId, string $serverId): array
    {
        $sites = [];

        foreach ($this->sites->forServer($serverId) as $site) {
            if ($site->organizationId === $organizationId && ! $site->runtime->usesDocker()) {
                $sites[$site->id] = $site;
            }
        }

        $items = [];

        foreach ([Worker::class => 'worker', Daemon::class => 'daemon'] as $model => $kind) {
            foreach ($model::query()->where('organization_id', $organizationId)->whereIn('site_id', array_keys($sites))->orderBy('created_at')->get() as $process) {
                /** @var Worker|Daemon $process */
                if (! $process->runsOn($serverId)) {
                    continue;
                }

                /** @var SiteData $site */
                $site = $sites[$process->site_id];
                $limits = $this->defaults->effective($process->resourceLimits(), $site->id);
                $label = $process instanceof Worker ? StateCompiler::workerLabel($process) : $process->name;
                $items[] = new CapacityItem($kind, $process->id, "{$site->name} · {$label}", $limits->memoryLimit, $limits->memoryReservation, $limits->cpus, StatusPoller::url($site->id, $kind));
            }
        }

        return $items;
    }
}
