<?php

namespace Falak\Limits\Application;

use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Processes\Contracts\Data\ProcessOwner;
use Falak\Processes\Contracts\ProcessOwners;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;

/**
 * Maps an agent's service event to the Falak service it is about, within the reporting server's organization only:
 *
 *  - a container by its labels: a database instance (falak.db.instance), a Docker site (falak.site, the slug), a
 *    compose service (the project is the site's slug);
 *  - a slice by its key: site_<slug with underscores>, worker_<id>, daemon_<id>;
 *  - a program by its proc.apply name (Processes' last applied state of the server).
 *
 * Unknown or foreign services resolve to null (and are ignored).
 */
final class ServiceResolver
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ProcessOwners $processes,
        private readonly DatabaseDirectory $databases,
    ) {}

    /**
     * @param  array{kind: string, source: string, name: string, site: ?string, project: ?string, service: ?string, instance: ?string}  $event
     */
    public function resolve(string $organizationId, string $serverId, array $event): ?ResolvedService
    {
        $resolved = match ($event['source']) {
            'container' => $this->container($serverId, $event),
            'slice' => $this->slice($serverId, $event['name']),
            'program' => $this->program($serverId, $event['name']),
            default => null,
        };

        return $resolved !== null && $resolved->organizationId === $organizationId ? $resolved : null;
    }

    /**
     * @param  array{site: ?string, project: ?string, service: ?string, instance: ?string}  $event
     */
    private function container(string $serverId, array $event): ?ResolvedService
    {
        if ($event['instance'] !== null) {
            foreach ($this->databases->forServer($serverId) as $database) {
                if ($database->instanceId === $event['instance']) {
                    return new ResolvedService($database->organizationId, 'database', $database->instanceId, $database->siteId, "Database {$database->name}", "/databases/{$database->id}", $database->memoryMb);
                }
            }

            return null;
        }

        if ($event['site'] !== null && ($site = $this->siteBySlug($serverId, $event['site'])) !== null) {
            return $this->siteService($site);
        }

        if ($event['project'] !== null && $event['service'] !== null && ($site = $this->siteBySlug($serverId, $event['project'])) !== null && $site->runtime === SiteRuntime::Compose) {
            $limits = $site->composeServiceLimits($event['service']);

            return new ResolvedService($site->organizationId, 'compose_service', "{$site->id}:{$event['service']}", $site->id, "{$site->name} · {$event['service']}", "/sites/{$site->id}", $limits->memoryLimit);
        }

        return null;
    }

    private function slice(string $serverId, string $name): ?ResolvedService
    {
        [$kind, $ref] = array_pad(explode('_', $name, 2), 2, '');

        if ($kind === 'site') {
            $site = $this->siteBySlug($serverId, str_replace('_', '-', $ref));

            return $site !== null ? $this->siteService($site) : null;
        }

        return in_array($kind, ['worker', 'daemon'], true) ? $this->owner($this->processes->process($kind, $ref, $serverId)) : null;
    }

    private function program(string $serverId, string $name): ?ResolvedService
    {
        return $this->owner($this->processes->program($serverId, $name));
    }

    private function owner(?ProcessOwner $owner): ?ResolvedService
    {
        return $owner !== null ? new ResolvedService($owner->organizationId, $owner->kind, $owner->id, $owner->siteId, $owner->label, $owner->url, $owner->memoryLimitMb) : null;
    }

    private function siteService(SiteData $site): ResolvedService
    {
        return new ResolvedService($site->organizationId, 'site', $site->id, $site->id, $site->name, "/sites/{$site->id}", $site->limits->memoryLimit);
    }

    private function siteBySlug(string $serverId, string $slug): ?SiteData
    {
        foreach ($this->sites->forServer($serverId) as $site) {
            if ($site->slug === $slug) {
                return $site;
            }
        }

        return null;
    }
}
