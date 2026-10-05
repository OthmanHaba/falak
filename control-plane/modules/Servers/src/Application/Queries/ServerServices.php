<?php

namespace Falak\Servers\Application\Queries;

use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;

/**
 * Which services (sites, databases) run on which servers, read through the owning modules' contracts.
 * Each service links to its canvas deep link (Projects) when it is placed in an environment.
 *
 * @phpstan-type ServerService array{kind: string, id: string, name: string, icon: string, subtitle: ?string, status: string, role: ?string, url: string}
 */
final class ServerServices
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly DatabaseDirectory $databases,
        private readonly ProjectDirectory $projects,
    ) {}

    /**
     * @return list<ServerService>
     */
    public function forServer(string $serverId): array
    {
        $services = [];

        foreach ($this->sites->forServer($serverId) as $site) {
            $services[] = $this->site($site, $serverId);
        }

        foreach ($this->databases->forServer($serverId) as $database) {
            $services[] = $this->database($database);
        }

        return $services;
    }

    /**
     * Lightweight per-server lists for the fleet table (no canvas URLs: one lookup per service would be N queries).
     *
     * @param  list<string>  $serverIds
     * @return array<string, list<array{kind: string, id: string, name: string, icon: string}>>
     */
    public function forServers(string $organizationId, array $serverIds): array
    {
        $byServer = array_fill_keys($serverIds, []);

        foreach ($this->sites->forOrganization($organizationId) as $site) {
            foreach ($site->targets as $target) {
                if (isset($byServer[$target->serverId])) {
                    $byServer[$target->serverId][] = ['kind' => 'site', 'id' => $site->id, 'name' => $site->name, 'icon' => self::siteIcon($site)];
                }
            }
        }

        foreach ($this->databases->forOrganization($organizationId) as $database) {
            if (isset($byServer[$database->serverId])) {
                $byServer[$database->serverId][] = ['kind' => 'database', 'id' => $database->id, 'name' => $database->name, 'icon' => $database->engine];
            }
        }

        return $byServer;
    }

    /**
     * @return ServerService
     */
    private function site(SiteData $site, string $serverId): array
    {
        $target = collect($site->targets)->firstWhere('serverId', $serverId);

        return [
            'kind' => 'site',
            'id' => $site->id,
            'name' => $site->name,
            'icon' => self::siteIcon($site),
            'subtitle' => $site->repository !== null ? $site->repository.($site->branch ? "@{$site->branch}" : '') : $site->runtime->label(),
            'status' => $target?->status->value ?? 'inactive',
            'role' => $target?->role->value,
            'url' => $this->projects->serviceUrl(ServiceKind::Site, $site->id) ?? "/sites/{$site->id}",
        ];
    }

    /**
     * @return ServerService
     */
    private function database(DatabaseData $database): array
    {
        return [
            'kind' => 'database',
            'id' => $database->id,
            'name' => $database->name,
            'icon' => $database->engine,
            'subtitle' => trim(self::engineLabel($database->engine).' '.($database->engineVersion ?? '')),
            'status' => $database->status,
            'role' => null,
            'url' => $this->projects->serviceUrl(ServiceKind::Database, $database->id) ?? '/databases',
        ];
    }

    private static function siteIcon(SiteData $site): string
    {
        if ($site->runtime === SiteRuntime::Function) {
            return 'function';
        }

        return match ($site->framework) {
            Framework::Node => match ($site->runtime) {
                SiteRuntime::Bun => 'bun',
                SiteRuntime::Deno => 'deno',
                SiteRuntime::Docker, SiteRuntime::Compose => 'docker',
                default => 'node',
            },
            default => $site->framework->value,
        };
    }

    private static function engineLabel(string $engine): string
    {
        return match ($engine) {
            'postgresql' => 'PostgreSQL',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'redis' => 'Redis',
            default => ucfirst($engine),
        };
    }
}
