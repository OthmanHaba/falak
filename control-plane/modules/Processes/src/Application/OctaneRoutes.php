<?php

namespace Kiln\Processes\Application;

use Illuminate\Support\Str;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Processes\Domain\Enums\OctaneRouteStatus;
use Kiln\Processes\Domain\Models\OctaneRoute;
use Kiln\Processes\Events\OctaneRoutingChanged;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\TargetStatus;

/**
 * Octane routing state per (site, server) and the ordering around it:
 *
 *  - enable: the program is converged (proc.apply) → a probe waits until 127.0.0.1:<port> answers HTTP →
 *    Listening → {@see OctaneRoutingChanged} → the edge switches to reverse_proxy.
 *  - disable: Listening → Draining → the edge switches back to serving the site directly → once no applied
 *    or pending edge config proxies to it ({@see drained()}) the route is dropped and proc.apply stops the program.
 *  - port / server change: back to Starting (the edge serves directly until the new process answers).
 */
final class OctaneRoutes
{
    public const PROBE_PREFIX = 'processes.octane-probe:';

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly AgentGateway $agents,
        private readonly EdgeRoutes $edge,
    ) {}

    /**
     * Align the routes of a site with its settings and ready targets. Returns the servers whose programs change.
     *
     * @return list<string>
     */
    public function sync(string $siteId, ?string $organizationId = null): array
    {
        $site = $this->sites->find($siteId);
        $existing = OctaneRoute::query()->where('site_id', $siteId)->when($organizationId, fn ($q, $org) => $q->where('organization_id', $org))->get()->keyBy('server_id');
        $want = $site !== null && self::wantsOctane($site) ? $site->readyServerIds() : [];
        $touched = [];

        foreach ($want as $serverId) {
            /** @var SiteData $site */
            $route = $existing->get($serverId);
            $server = $site->laravel->octaneServer;
            $port = (int) $site->laravel->octanePort;

            if ($route !== null && $route->port === $port && $route->octane_server === $server) {
                if ($route->status === OctaneRouteStatus::Draining) {
                    // Switched back on before the edge dropped it: the process never stopped.
                    $route->forceFill(['status' => OctaneRouteStatus::Starting, 'draining_since' => null])->save();
                    $touched[] = $serverId;
                }

                continue;
            }

            $wasListening = $route?->status === OctaneRouteStatus::Listening;
            $route ??= new OctaneRoute(['site_id' => $site->id, 'server_id' => $serverId]);
            $route->forceFill([
                'organization_id' => $site->organizationId,
                'octane_server' => $server,
                'port' => $port,
                'status' => OctaneRouteStatus::Starting,
                'probe_command_id' => null,
                'error' => null,
                'listening_at' => null,
                'draining_since' => null,
            ])->save();
            $touched[] = $serverId;

            if ($wasListening) {
                OctaneRoutingChanged::dispatch($site->id, $serverId, $site->organizationId, null);
            }
        }

        foreach ($existing as $serverId => $route) {
            if (in_array($serverId, $want, true)) {
                continue;
            }

            $stillTargeted = $site !== null && in_array($serverId, $site->serverIds(), true);

            if ($route->status === OctaneRouteStatus::Listening && $stillTargeted) {
                $route->forceFill(['status' => OctaneRouteStatus::Draining, 'draining_since' => now()])->save();
                OctaneRoutingChanged::dispatch($route->site_id, $route->server_id, $route->organization_id, null);
            } elseif ($route->status !== OctaneRouteStatus::Draining || ! $stillTargeted) {
                // Never proxied (or the site left the server, taking its route along): nothing to wait for.
                $route->delete();
                $touched[] = (string) $serverId;
            }
        }

        return array_values(array_unique($touched));
    }

    public static function wantsOctane(SiteData $site): bool
    {
        return $site->framework->isLaravel() && $site->runtime->isPhp() && $site->laravel->servesOctane() && $site->laravel->octaneServer !== null;
    }

    /**
     * Probe every route of the server (or of one site on it) that is not verified yet.
     */
    public function probeServer(string $serverId, ?string $siteId = null): int
    {
        $routes = OctaneRoute::query()
            ->where('server_id', $serverId)
            ->when($siteId, fn ($q, $id) => $q->where('site_id', $id))
            ->whereIn('status', [OctaneRouteStatus::Starting, OctaneRouteStatus::Failed])
            ->get();

        foreach ($routes as $route) {
            $this->probe($route);
        }

        return $routes->count();
    }

    /**
     * Ask the agent to wait (up to processes.octane_probe_seconds) until Octane answers HTTP on its port.
     */
    public function probe(OctaneRoute $route): void
    {
        $site = $this->sites->find($route->site_id);
        $target = $site?->target($route->server_id);

        if ($site === null || $target === null || $target->status !== TargetStatus::Ready) {
            return;
        }

        $wait = max(1, (int) config('processes.octane_probe_seconds', 60));

        try {
            $handle = $this->agents->dispatch($route->server_id, 'system.exec', [
                'script' => self::probeScript($route->port, $wait),
                'shell' => '/bin/bash',
                'user' => $site->unixUser,
            ], $wait + 30, self::PROBE_PREFIX."{$route->site_id}:{$route->server_id}:".Str::ulid());
        } catch (AgentUnavailable) {
            return;
        }

        $route->forceFill(['probe_command_id' => $handle->id, 'checked_at' => now()])->save();
    }

    /**
     * Any HTTP answer (even a 500) proves Octane is serving; connection refused / no answer is retried each second.
     */
    public static function probeScript(int $port, int $seconds): string
    {
        return <<<BASH
            set -u
            for _ in \$(seq 1 {$seconds}); do
              if command -v curl >/dev/null 2>&1; then
                curl -s -o /dev/null --max-time 5 http://127.0.0.1:{$port}/ && exit 0
              elif (exec 3<>/dev/tcp/127.0.0.1/{$port}) 2>/dev/null; then
                exit 0
              fi
              sleep 1
            done
            echo "Octane is not answering on 127.0.0.1:{$port} after {$seconds}s" >&2
            exit 1
            BASH;
    }

    public function settleProbe(string $commandId, bool $ok, ?string $error): void
    {
        $route = OctaneRoute::query()->where('probe_command_id', $commandId)->first();

        // Superseded by a newer probe, or the route changed (sync clears the probe id).
        if ($route === null || ! in_array($route->status, [OctaneRouteStatus::Starting, OctaneRouteStatus::Failed], true)) {
            return;
        }

        if (! $ok) {
            $route->forceFill(['status' => OctaneRouteStatus::Failed, 'error' => Str::limit($error ?: 'Octane did not answer.', 990)])->save();

            return;
        }

        $route->forceFill(['status' => OctaneRouteStatus::Listening, 'error' => null, 'listening_at' => now()])->save();
        OctaneRoutingChanged::dispatch($route->site_id, $route->server_id, $route->organization_id, $route->port);
    }

    /**
     * Draining routes of the server the edge no longer proxies to (neither the applied nor a pending config) are
     * dropped, so the next proc.apply stops their programs. Returns true when any was dropped.
     */
    public function drained(string $serverId): bool
    {
        $dropped = false;

        foreach (OctaneRoute::query()->where('server_id', $serverId)->where('status', OctaneRouteStatus::Draining)->get() as $route) {
            if (! $this->edge->proxiesToOctane($route->site_id, $serverId)) {
                $route->delete();
                $dropped = true;
            }
        }

        return $dropped;
    }

    /**
     * Draining routes whose edge apply never came (agent offline, edge apply failed) stop after a grace period.
     *
     * @return list<string> servers to converge
     */
    public function expireDrains(): array
    {
        $cutoff = now()->subSeconds(max(1, (int) config('processes.octane_drain_timeout_seconds', 600)));
        $routes = OctaneRoute::query()->where('status', OctaneRouteStatus::Draining)->where('draining_since', '<', $cutoff)->get();

        foreach ($routes as $route) {
            $route->delete();
        }

        return array_values(array_unique($routes->pluck('server_id')->all()));
    }

    /**
     * Routes the program set of a server must keep while draining: site id => route.
     *
     * @return array<string, OctaneRoute>
     */
    public function draining(string $serverId): array
    {
        return OctaneRoute::query()->where('server_id', $serverId)->where('status', OctaneRouteStatus::Draining)->get()->keyBy('site_id')->all();
    }

    public static function isProbe(?string $idempotencyKey): bool
    {
        return $idempotencyKey !== null && str_starts_with($idempotencyKey, self::PROBE_PREFIX);
    }
}
