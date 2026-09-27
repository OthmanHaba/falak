<?php

namespace Kiln\Sites\Application;

use Illuminate\Validation\ValidationException;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;
use Kiln\SourceControl\Contracts\SourceControlGateway;

/**
 * Cross-module validation shared by the create / update actions.
 */
final class SiteRules
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly AgentDirectory $agents,
        private readonly SourceControlGateway $sourceControl,
    ) {}

    /**
     * Servers must belong to the organization, host sites and support the runtime.
     *
     * @param  list<string>  $serverIds
     * @return list<ServerData>
     *
     * @throws ValidationException
     */
    public function targets(string $organizationId, array $serverIds, SiteRuntime $runtime, ?string $phpVersion, BuildMode $buildMode, string $field = 'server_ids'): array
    {
        if ($serverIds === []) {
            throw ValidationException::withMessages([$field => 'Pick at least one server.']);
        }

        $servers = [];

        foreach (array_values(array_unique($serverIds)) as $serverId) {
            $server = $this->servers->find($serverId);

            if (! $server || $server->organizationId !== $organizationId) {
                throw ValidationException::withMessages([$field => 'One of the selected servers does not exist.']);
            }

            if (! $server->type->hostsSites()) {
                throw ValidationException::withMessages([$field => "{$server->name} is a {$server->type->label()} and cannot host sites."]);
            }

            if ($server->status === ServerStatus::Deleting) {
                throw ValidationException::withMessages([$field => "{$server->name} is being deleted."]);
            }

            $this->runtimeSupported($server, $runtime, $phpVersion, $field);

            if ($buildMode === BuildMode::OnServer) {
                $memory = $this->agents->forServer($server->id)?->facts['memory_bytes'] ?? null;

                if (! is_numeric($memory) || (int) $memory < BuildMode::ON_SERVER_MIN_MEMORY_BYTES) {
                    throw ValidationException::withMessages(['build_mode' => "On-server builds need at least 2 GB of RAM; {$server->name} ".(is_numeric($memory) ? 'has '.round(((int) $memory) / 1024 ** 3, 1).' GB.' : 'has not reported its memory yet.')]);
                }
            }

            $servers[] = $server;
        }

        return $servers;
    }

    public function runtimeSupported(ServerData $server, SiteRuntime $runtime, ?string $phpVersion, string $field = 'server_ids'): void
    {
        $error = match (true) {
            $runtime->isPhp() && $server->phpRuntime === null => "{$server->name} does not run PHP.",
            $runtime === SiteRuntime::FrankenPhp && $server->phpRuntime !== 'frankenphp' => "{$server->name} does not run FrankenPHP; use the PHP-FPM runtime.",
            $runtime->isPhp() && $phpVersion !== null && ! in_array($phpVersion, $server->phpVersions, true) => "PHP {$phpVersion} is not installed on {$server->name}.",
            $runtime->isContainer() && ! $server->docker => "Docker is not installed on {$server->name}.",
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages([$field => $error]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function runtimeAndFramework(Framework $framework, SiteRuntime $runtime, BuildMode $buildMode): void
    {
        if ($framework->isPhp() !== $runtime->isPhp() && $framework !== Framework::Static && $runtime !== SiteRuntime::Docker) {
            throw ValidationException::withMessages(['runtime' => "{$framework->label()} sites cannot use the {$runtime->label()} runtime."]);
        }

        if (! in_array($buildMode, $runtime->buildModes(), true)) {
            throw ValidationException::withMessages(['build_mode' => "{$runtime->label()} sites cannot use {$buildMode->label()} builds."]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function connection(string $organizationId, ?string $connectionId): void
    {
        if ($connectionId === null) {
            return;
        }

        if ($this->sourceControl->connection($connectionId)?->organizationId !== $organizationId) {
            throw ValidationException::withMessages(['source_connection_id' => 'Unknown source control connection.']);
        }
    }

    /**
     * A free local port for the app on all given servers.
     *
     * @param  list<string>  $serverIds
     * @param  list<int>  $alsoTaken  ports reserved by the caller (e.g. earlier public services of the same site)
     */
    public function freePort(array $serverIds, ?string $exceptSiteId = null, array $alsoTaken = []): int
    {
        $used = [...$this->portsInUse($serverIds, $exceptSiteId), ...$alsoTaken];
        [$from, $to] = array_map('intval', (array) config('sites.app_port_range', [3000, 3999]));

        for ($port = $from; $port <= $to; $port++) {
            if (! in_array($port, $used, true)) {
                return $port;
            }
        }

        throw ValidationException::withMessages(['app_port' => 'No free application port left on the selected servers.']);
    }

    /**
     * @param  list<string>  $serverIds
     *
     * @throws ValidationException
     */
    public function portAvailable(int $port, array $serverIds, ?string $exceptSiteId = null): void
    {
        if (in_array($port, $this->portsInUse($serverIds, $exceptSiteId), true)) {
            throw ValidationException::withMessages(['app_port' => "Port {$port} is already used by another site on these servers."]);
        }
    }

    /**
     * Loopback ports used by sites on the servers: app ports and compose public service ports.
     *
     * @param  list<string>  $serverIds
     * @return list<int>
     */
    public function portsInUse(array $serverIds, ?string $exceptSiteId = null): array
    {
        $sites = Site::query()
            ->when($exceptSiteId, fn ($q, $id) => $q->whereKeyNot($id))
            ->whereIn('id', SiteTarget::query()->select('site_id')->whereIn('server_id', $serverIds))
            ->where(fn ($q) => $q->whereNotNull('app_port')->orWhereNotNull('public_services'))
            ->get(['id', 'app_port', 'public_services']);

        $ports = [];

        foreach ($sites as $site) {
            if ($site->app_port !== null) {
                $ports[] = (int) $site->app_port;
            }

            foreach ((array) $site->public_services as $public) {
                if (is_array($public) && isset($public['host_port'])) {
                    $ports[] = (int) $public['host_port'];
                }
            }
        }

        return array_values(array_unique($ports));
    }
}
