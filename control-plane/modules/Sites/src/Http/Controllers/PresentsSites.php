<?php

namespace Kiln\Sites\Http\Controllers;

use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;
use Kiln\Sites\Domain\Presets\Preset;
use Kiln\Sites\Infrastructure\EloquentSiteHeaders;
use Kiln\SourceControl\Contracts\Data\ConnectionData;
use Kiln\SourceControl\Contracts\SourceControlGateway;

trait PresentsSites
{
    /**
     * @return array<string, mixed>
     */
    protected function header(Site $site): array
    {
        return app(EloquentSiteHeaders::class)->forSite($site);
    }

    protected function status(Site $site): string
    {
        $statuses = $site->targets->map(fn (SiteTarget $target) => $target->status);

        return match (true) {
            $statuses->isEmpty() => 'no_servers',
            $statuses->contains(TargetStatus::Failed) => 'failed',
            $statuses->contains(fn (TargetStatus $status) => in_array($status, [TargetStatus::Pending, TargetStatus::Provisioning], true)) => 'provisioning',
            default => 'ready',
        };
    }

    /**
     * @param  array<string, ServerData>  $servers
     * @return list<array<string, mixed>>
     */
    protected function targets(Site $site, array $servers): array
    {
        return $site->targets->map(fn (SiteTarget $target) => [
            'id' => $target->id,
            'server_id' => $target->server_id,
            'server_name' => $servers[$target->server_id]->name ?? 'deleted server',
            'server_ip' => $servers[$target->server_id]->ipv4 ?? null,
            'role' => $target->role->value,
            'status' => $target->status->value,
            'status_message' => $target->status_message,
            'command_id' => $target->command_id,
        ])->values()->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, ServerData>
     */
    protected function serversById(ServerDirectory $directory, array $ids): array
    {
        $servers = [];

        foreach (array_unique($ids) as $id) {
            if ($server = $directory->find($id)) {
                $servers[$id] = $server;
            }
        }

        return $servers;
    }

    /**
     * Options for the create wizard and the settings page.
     *
     * @return array<string, mixed>
     */
    protected function options(string $organizationId, ServerDirectory $servers, AgentDirectory $agents, SourceControlGateway $sourceControl): array
    {
        $candidates = array_values(array_filter($servers->forOrganization($organizationId), fn (ServerData $server) => $server->type->hostsSites()));
        $facts = $agents->forServers(array_map(fn (ServerData $server) => $server->id, $candidates));

        return [
            'frameworks' => array_map(function (Framework $framework) {
                $preset = Preset::for($framework);

                return [
                    'value' => $framework->value,
                    'label' => $framework->label(),
                    'runtimes' => array_map(fn (SiteRuntime $runtime) => $runtime->value, $preset->runtimes),
                    'web_directory' => $preset->webDirectory,
                    'is_php' => $framework->isPhp(),
                    'is_laravel' => $framework->isLaravel(),
                ];
            }, Framework::cases()),
            'runtimes' => array_map(fn (SiteRuntime $runtime) => [
                'value' => $runtime->value,
                'label' => $runtime->label(),
                'is_php' => $runtime->isPhp(),
                'proxies' => $runtime->proxiesToPort(),
                'container' => $runtime->isContainer(),
                'build_modes' => array_map(fn (BuildMode $mode) => $mode->value, $runtime->buildModes()),
            ], SiteRuntime::cases()),
            'build_modes' => array_map(fn (BuildMode $mode) => ['value' => $mode->value, 'label' => $mode->label()], BuildMode::cases()),
            'servers' => array_map(fn (ServerData $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'type' => $server->type->value,
                'type_label' => $server->type->label(),
                'status' => $server->status->value,
                'ipv4' => $server->ipv4,
                'php_runtime' => $server->phpRuntime,
                'php_versions' => $server->phpVersions,
                'default_php' => $server->defaultPhpVersion,
                'docker' => $server->docker,
                'memory_bytes' => is_numeric($facts[$server->id]->facts['memory_bytes'] ?? null) ? (int) $facts[$server->id]->facts['memory_bytes'] : null,
            ], $candidates),
            'connections' => array_map(fn (ConnectionData $connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'provider' => $connection->provider->value,
                'provider_label' => $connection->provider->label(),
                'has_api' => $connection->provider->hasApi(),
            ], $sourceControl->connections($organizationId)),
            'php_versions' => array_values((array) config('sites.php_versions')),
            'node_versions' => array_values((array) config('sites.node_versions')),
            'test_domain' => config('sites.test_domain') ?: null,
            'on_server_min_memory_bytes' => BuildMode::ON_SERVER_MIN_MEMORY_BYTES,
        ];
    }
}
