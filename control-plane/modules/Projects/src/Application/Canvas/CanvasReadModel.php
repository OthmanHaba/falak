<?php

namespace Kiln\Projects\Application\Canvas;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Kiln\Databases\Contracts\Data\DatabaseData;
use Kiln\Databases\Contracts\DatabaseDirectory;
use Kiln\Deployments\Contracts\Data\DeploymentSummary;
use Kiln\Deployments\Contracts\DeploymentDirectory;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Contracts\VariableReferences;
use Kiln\Projects\Domain\Models\Environment;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\ComposeInspector;
use Kiln\Sites\Contracts\ComposeSites;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteDomains;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetStatus;

/**
 * Everything the canvas renders for one environment, in one request (UI_DESIGN §9 `Canvas`):
 * services with live status (deployments, targets, servers), and edges derived from variable references.
 *
 * @phpstan-type CanvasService array{id: string, kind: string, ref_id: string, name: string, icon: string, position: array{x: int, y: int}, status: string, status_label: string, url: ?string, subtitle: ?string, servers: list<array{id: string, name: string, leader: bool, online: bool}>, last_deployment: ?array{id: string, status: string, commit: ?string, message: ?string, finished_at: ?string}}
 */
final class CanvasReadModel
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly SiteDomains $domains,
        private readonly DatabaseDirectory $databases,
        private readonly DeploymentDirectory $deployments,
        private readonly ServerDirectory $servers,
        private readonly AgentDirectory $agents,
        private readonly VariableReferences $references,
        private readonly ComposeSites $compose,
        private readonly ComposeInspector $inspector,
    ) {}

    /**
     * @return array{services: list<CanvasService>, edges: list<array{from: string, to: string}>}
     */
    public function for(Environment $environment): array
    {
        /** @var list<Service> $services */
        $services = $environment->services()->get()->all();

        $siteIds = array_values(array_map(fn (Service $s) => $s->ref_id, array_filter($services, fn (Service $s) => $s->kind === ServiceKind::Site)));
        $databaseIds = array_values(array_map(fn (Service $s) => $s->ref_id, array_filter($services, fn (Service $s) => $s->kind === ServiceKind::Database)));

        /** @var array<string, SiteData> $sites */
        $sites = [];

        foreach ($siteIds as $siteId) {
            if ($site = $this->sites->find($siteId)) {
                $sites[$siteId] = $site;
            }
        }

        $databases = $this->databases->findMany($databaseIds);
        $deployments = $this->deployments->currentForSites(array_keys($sites));
        $domains = $this->domains->primaryDomains(array_keys($sites));

        $serverIds = array_values(array_unique([
            ...array_merge([], ...array_map(fn (SiteData $site) => $site->serverIds(), array_values($sites))),
            ...array_map(fn (DatabaseData $database) => $database->serverId, array_values($databases)),
        ]));
        $servers = $this->servers($serverIds);
        $agents = $this->agents->forServers($serverIds);

        $cards = [];

        foreach ($services as $service) {
            $card = match ($service->kind) {
                ServiceKind::Site => isset($sites[$service->ref_id])
                    ? $this->site($service, $sites[$service->ref_id], $deployments[$service->ref_id] ?? null, $domains[$service->ref_id] ?? null, $servers, $agents)
                    : null,
                ServiceKind::Database => isset($databases[$service->ref_id])
                    ? $this->database($service, $databases[$service->ref_id], $servers, $agents)
                    : null,
            };

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return ['services' => $cards, 'edges' => $this->edges($services, $sites)];
    }

    /**
     * @param  array<string, ServerData>  $servers
     * @param  array<string, mixed>  $agents
     * @return CanvasService
     */
    private function site(Service $service, SiteData $site, ?DeploymentSummary $deployment, ?string $domain, array $servers, array $agents): array
    {
        [$status, $label] = $this->siteStatus($site, $deployment);
        $host = $domain ?? $site->testDomain;
        $subtitle = implode(' · ', array_filter([
            $site->framework->label(),
            $site->runtime->isPhp() && $site->phpVersion ? "PHP {$site->phpVersion}" : $site->runtime->label(),
        ]));

        if ($site->compose !== null) {
            [$subtitle, $status, $label] = $this->composeCard($site, $status, $label);
        }

        return [
            ...$this->base($service),
            'icon' => self::siteIcon($site),
            'status' => $status,
            'status_label' => $label,
            'url' => $host !== null ? "https://{$host}" : null,
            'subtitle' => $subtitle,
            'servers' => array_map(fn ($target) => $this->server($target->serverId, $target->isLeader(), $servers, $agents), $site->targets),
            'last_deployment' => $deployment !== null ? [
                'id' => $deployment->id,
                'status' => $deployment->status,
                'commit' => $deployment->commit !== null ? substr($deployment->commit, 0, 7) : null,
                'message' => $deployment->message,
                'finished_at' => $deployment->finishedAt?->format(DateTimeInterface::ATOM),
            ] : null,
        ];
    }

    /**
     * @param  array<string, ServerData>  $servers
     * @param  array<string, mixed>  $agents
     * @return CanvasService
     */
    private function database(Service $service, DatabaseData $database, array $servers, array $agents): array
    {
        [$status, $label] = match ($database->status) {
            'active' => ['active', 'Active'],
            'pending' => ['provisioning', 'Creating'],
            'failed' => ['failed', 'Failed'],
            default => ['inactive', 'Deleting'],
        };

        return [
            ...$this->base($service),
            'icon' => $database->engine,
            'status' => $status,
            'status_label' => $label,
            'url' => null,
            'subtitle' => implode(' · ', array_filter([
                trim(self::engineLabel($database->engine).' '.($database->engineVersion ?? '')),
                $servers[$database->serverId]->name ?? null,
            ])),
            'servers' => [$this->server($database->serverId, false, $servers, $agents)],
            'last_deployment' => null,
        ];
    }

    /**
     * @return array{id: string, kind: string, ref_id: string, name: string, position: array{x: int, y: int}}
     */
    private function base(Service $service): array
    {
        return [
            'id' => $service->id,
            'kind' => $service->kind->value,
            'ref_id' => $service->ref_id,
            'name' => $service->name,
            'position' => ['x' => $service->x, 'y' => $service->y],
        ];
    }

    /**
     * Compose sites: "Compose · 3 services"; an active site whose services report unhealthy / exited
     * containers is shown as crashed.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function composeCard(SiteData $site, string $status, string $label): array
    {
        $states = $this->compose->status($site->id);
        $names = array_values(array_unique(array_map(fn ($state) => $state->service, $states)));

        if ($names === [] && ($content = $this->compose->content($site->id)) !== null) {
            $names = $this->inspector->parse($content->content)->serviceNames();
        }

        $count = count($names);
        $subtitle = 'Compose'.($count > 0 ? ' · '.$count.' '.($count === 1 ? 'service' : 'services') : '');

        if ($status === 'active' && $states !== []) {
            $failing = array_values(array_unique(array_map(fn ($state) => $state->service, array_filter($states, fn ($state) => $state->failing()))));

            if ($failing !== []) {
                $healthy = $count - count($failing);

                return [$subtitle, 'crashed', "{$healthy}/{$count} services healthy · ".implode(', ', array_slice($failing, 0, 2)).' down'];
            }
        }

        return [$subtitle, $status, $label];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function siteStatus(SiteData $site, ?DeploymentSummary $deployment): array
    {
        $targets = array_map(fn ($target) => $target->status->value, $site->targets);

        if ($deployment !== null && $deployment->isActive()) {
            return $deployment->status === 'building'
                ? ['building', 'Building']
                : ['deploying', 'Deploying '.($deployment->progress ?? 0).'%'];
        }

        if ($targets === []) {
            return ['inactive', 'No servers'];
        }

        if (in_array(TargetStatus::Failed->value, $targets, true)) {
            return ['failed', 'Server setup failed'];
        }

        if (array_intersect([TargetStatus::Pending->value, TargetStatus::Provisioning->value], $targets) !== []) {
            return ['provisioning', 'Provisioning'];
        }

        return match ($deployment?->status) {
            null => ['inactive', 'Not deployed'],
            'queued' => ['queued', 'Queued'],
            'succeeded' => ['active', 'Active · '.self::ago($deployment->finishedAt ?? $deployment->createdAt)],
            'failed' => ['failed', 'Failed · '.self::ago($deployment->finishedAt ?? $deployment->createdAt)],
            default => ['inactive', 'Cancelled · '.self::ago($deployment->finishedAt ?? $deployment->createdAt)],
        };
    }

    /**
     * @param  array<string, ServerData>  $servers
     * @param  array<string, mixed>  $agents
     * @return array{id: string, name: string, leader: bool, online: bool}
     */
    private function server(string $serverId, bool $leader, array $servers, array $agents): array
    {
        $agent = $agents[$serverId] ?? null;

        return [
            'id' => $serverId,
            'name' => $servers[$serverId]->name ?? 'deleted server',
            'leader' => $leader,
            'online' => $agent !== null && $agent->isOnline(),
        ];
    }

    /**
     * A site whose variables reference another service of the environment points at it.
     *
     * @param  list<Service>  $services
     * @param  array<string, SiteData>  $sites
     * @return list<array{from: string, to: string}>
     */
    private function edges(array $services, array $sites): array
    {
        $byHandle = [];

        foreach ($services as $service) {
            $byHandle[Service::handle($service->name)] = $service;
        }

        $edges = [];

        foreach ($services as $service) {
            if ($service->kind !== ServiceKind::Site || ! isset($sites[$service->ref_id])) {
                continue;
            }

            $variables = $this->sites->environment($service->ref_id)?->variables ?? [];

            foreach ($this->references->referencesIn($variables) as $reference) {
                $target = $byHandle[Service::handle($reference['service'])] ?? null;

                if ($target !== null && $target->id !== $service->id) {
                    $edges["{$service->id}>{$target->id}"] = ['from' => $service->id, 'to' => $target->id];
                }
            }
        }

        return array_values($edges);
    }

    /**
     * @param  list<string>  $serverIds
     * @return array<string, ServerData>
     */
    private function servers(array $serverIds): array
    {
        $servers = [];

        foreach ($serverIds as $serverId) {
            if ($server = $this->servers->find($serverId)) {
                $servers[$serverId] = $server;
            }
        }

        return $servers;
    }

    /** ServiceIcon key of a site: framework logo, runtime for generic Node sites. */
    public static function siteIcon(SiteData $site): string
    {
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

    public static function engineLabel(string $engine): string
    {
        return match ($engine) {
            'postgresql' => 'PostgreSQL',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'redis' => 'Redis',
            default => ucfirst($engine),
        };
    }

    private static function ago(DateTimeInterface $at): string
    {
        return Carbon::instance($at)->diffForHumans(null, true, true).' ago';
    }
}
