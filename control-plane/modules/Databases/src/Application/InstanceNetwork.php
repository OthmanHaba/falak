<?php

namespace Falak\Databases\Application;

use Falak\Databases\Contracts\Data\DatabaseConsumer;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Network\Contracts\PrivateNetwork;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;

/**
 * Who reaches a database container, and on which address and port.
 *
 * - Containers on its server in its environment (Docker sites, compose stacks): its DNS name on the environment's Docker
 *   network (falak-env-<id>), the engine's own port. They join that network when they deploy.
 * - Native sites on its server: 127.0.0.1 and the instance's host port (published on loopback only).
 * - Other servers (native or containers, whose traffic leaves from their server's address): the instance server's
 *   private address on a network both share — a Falak private network (WireGuard) first, else the provider private
 *   network, only where membership is known (both servers created by Falak with the same credential, in the same region,
 *   of a provider that puts such servers on one private network by default: databases.provider_private_networks). A
 *   private IPv4 alone proves nothing. Never the public address: without a shared private network the reference stays
 *   unresolved. The host port is published there only while such a consumer exists.
 *
 * The consumers are the sites placed in the environment of the instance's databases (references only resolve there).
 */
final class InstanceNetwork
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly PrivateNetwork $network,
        private readonly ProjectDirectory $projects,
        private readonly SiteDirectory $sites,
    ) {}

    /**
     * The sites of the environments the instance's databases are placed in, as database consumers.
     *
     * @return list<DatabaseConsumer>
     */
    public function consumers(DatabaseInstance $instance): array
    {
        $environments = [];

        foreach ($instance->databases()->pluck('id') as $databaseId) {
            if (($placed = $this->projects->projectOf(ServiceKind::Database, $databaseId)) !== null) {
                $environments[$placed->environmentId] = true;
            }
        }

        $consumers = [];

        foreach (array_keys($environments) as $environmentId) {
            foreach ($this->projects->servicesIn($environmentId) as $service) {
                if ($service->kind !== ServiceKind::Site || ($site = $this->sites->find($service->refId)) === null) {
                    continue;
                }

                $consumers[] = new DatabaseConsumer($site->name, array_values(array_map('strtolower', $site->serverIds())), $site->runtime->usesDocker());
            }
        }

        return $consumers;
    }

    /**
     * Private addresses of the instance's server the host port should be published on: one per consumer on another
     * server that shares a private network with it.
     *
     * @return list<string>
     */
    public function desiredAddresses(DatabaseInstance $instance): array
    {
        $addresses = [];

        foreach ($this->consumers($instance) as $consumer) {
            if (array_diff($consumer->serverIds, [$instance->server_id]) !== [] && ($reach = $this->reach($instance, $consumer->serverIds))['host'] !== null) {
                $addresses[] = $reach['host'];
            }
        }

        $addresses = array_values(array_unique($addresses));
        sort($addresses);

        return $addresses;
    }

    /**
     * Who may reach the published port (the agent's DOCKER-USER rules drop everyone else): the consumers on other
     * servers, by their own addresses on the private network they share with the instance's server (/32), plus the
     * instance's public allowlist.
     *
     * @return list<string> IPv4 CIDRs
     */
    public function allowedSources(DatabaseInstance $instance): array
    {
        $sources = array_values((array) ($instance->allowed_sources ?? []));

        foreach ($this->consumers($instance) as $consumer) {
            if (array_diff($consumer->serverIds, [$instance->server_id]) !== []) {
                foreach ($this->reach($instance, $consumer->serverIds)['peers'] as $address) {
                    $sources[] = "{$address}/32";
                }
            }
        }

        $sources = array_values(array_unique($sources));
        sort($sources);

        return $sources;
    }

    /**
     * Where the consumer reaches the instance (null consumer: a native one on its server).
     *
     * @return array{host: ?string, port: int, reason: ?string}
     */
    public function hostFor(DatabaseInstance $instance, ?DatabaseConsumer $consumer): array
    {
        $loopback = ['host' => '127.0.0.1', 'port' => (int) $instance->host_port, 'reason' => null];

        if ($consumer === null) {
            return $loopback;
        }

        $label = "{$instance->engine->label()} {$instance->name} on {$instance->server_name}";
        $elsewhere = array_values(array_diff(array_map('strtolower', $consumer->serverIds), [$instance->server_id]));

        if ($elsewhere === []) {
            if (! $consumer->containerized) {
                return $loopback;
            }

            if ($instance->network() === null) {
                return ['host' => null, 'port' => $instance->port, 'reason' => "{$consumer->name} runs in a container, but {$label} is not in a project environment, so it has no network containers join."];
            }

            return ['host' => $instance->hostname, 'port' => $instance->port, 'reason' => null];
        }

        $reach = $this->reach($instance, $consumer->serverIds);

        if ($reach['host'] === null) {
            $missing = $reach['missing'] !== [] ? $reach['missing'] : $elsewhere;

            return ['host' => null, 'port' => (int) $instance->host_port, 'reason' => "{$consumer->name} runs on ".$this->names($missing).", which shares no private network with {$instance->server_name}, and {$label} is never exposed on a public address for references. Add both servers to a private network (Network → Private networks)".($reach['missing'] === [] ? ' — one network that all of the site\'s servers share' : '').'.'];
        }

        if (! in_array($reach['host'], (array) ($instance->published_addresses ?? []), true)) {
            return ['host' => null, 'port' => (int) $instance->host_port, 'reason' => "{$consumer->name} runs on ".$this->names($elsewhere).", and {$label} is not published on {$reach['host']} ({$reach['via']}) yet: Falak is applying it; deploy again once it is done."];
        }

        return ['host' => $reach['host'], 'port' => (int) $instance->host_port, 'reason' => null];
    }

    /**
     * How $serverIds (servers other than the instance's) reach it: the instance server's address on the first network
     * all of them share with it. Falak private networks first, then the provider private network.
     *
     * @param  list<string>  $serverIds
     * @return array{host: ?string, via: ?string, peers: list<string>, missing: list<string>} peers: the consumers' own addresses on that network (what the firewall lets in); missing: servers sharing no private network with it at all
     */
    public function reach(DatabaseInstance $instance, array $serverIds): array
    {
        $serverIds = array_values(array_unique(array_diff(array_map('strtolower', $serverIds), [$instance->server_id])));
        $candidates = $this->candidates($instance->server_id, $serverIds);

        foreach ($candidates as $candidate) {
            if (count($candidate['peers']) === count($serverIds)) {
                return ['host' => $candidate['host'], 'via' => $candidate['via'], 'peers' => array_values($candidate['peers']), 'missing' => []];
            }
        }

        $covered = array_merge(...array_map(fn (array $c) => array_keys($c['peers']), $candidates ?: [['peers' => []]]));

        return ['host' => null, 'via' => null, 'peers' => [], 'missing' => array_values(array_diff($serverIds, $covered))];
    }

    /**
     * Every private address of the instance's server, most private first (connection details).
     *
     * @return list<array{address: string, via: string}>
     */
    public function privateAddresses(string $serverId): array
    {
        $out = [];

        foreach ($this->network->networksOf($serverId) as $membership) {
            $out[] = ['address' => $membership->address, 'via' => "private network {$membership->name}"];
        }

        if (($private = $this->servers->find($serverId)?->privateIpv4) !== null) {
            $out[] = ['address' => $private, 'via' => 'provider private network'];
        }

        return $out;
    }

    /** @param  list<string>  $serverIds */
    private function names(array $serverIds): string
    {
        return implode(', ', array_map(fn (string $id) => $this->servers->find($id)?->name ?? $id, $serverIds));
    }

    /**
     * @param  list<string>  $serverIds
     * @return list<array{host: string, via: string, peers: array<string, string>}>
     */
    private function candidates(string $instanceServerId, array $serverIds): array
    {
        $candidates = [];
        $peerNetworks = [];

        foreach ($serverIds as $id) {
            foreach ($this->network->networksOf($id) as $membership) {
                $peerNetworks[$membership->networkId][$id] = $membership->address;
            }
        }

        foreach ($this->network->networksOf($instanceServerId) as $membership) {
            $candidates[] = ['host' => $membership->address, 'via' => "private network {$membership->name}", 'peers' => $peerNetworks[$membership->networkId] ?? []];
        }

        $server = $this->servers->find($instanceServerId);

        if ($server?->privateIpv4 !== null) {
            $peers = [];

            foreach ($serverIds as $id) {
                $peer = $this->servers->find($id);

                if ($peer?->privateIpv4 !== null && self::shareProviderNetwork($server, $peer)) {
                    $peers[$id] = $peer->privateIpv4;
                }
            }

            $candidates[] = ['host' => $server->privateIpv4, 'via' => 'provider private network', 'peers' => $peers];
        }

        return $candidates;
    }

    /** Both servers are on one provider private network for sure (see the class doc). */
    private static function shareProviderNetwork(ServerData $a, ServerData $b): bool
    {
        if ($a->provider !== $b->provider) {
            return false;
        }

        if ($a->provider === 'custom') {
            return (bool) config('databases.custom_private_network', false);
        }

        return in_array($a->provider, (array) config('databases.provider_private_networks', []), true)
            && $a->providerCredentialId !== null && $a->providerCredentialId === $b->providerCredentialId
            && $a->region !== null && $a->region === $b->region;
    }
}
