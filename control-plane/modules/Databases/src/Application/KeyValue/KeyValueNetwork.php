<?php

namespace Kiln\Databases\Application\KeyValue;

use Kiln\Databases\Contracts\Data\DatabaseConsumer;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Network\Contracts\PrivateNetwork;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * Who reaches a Redis / Valkey instance, and on which address (feature db.redis.network).
 *
 * - Native sites on the instance's server: 127.0.0.1.
 * - Containers on the instance's server (Docker sites, compose stacks, functions): the Docker default bridge's
 *   address (docker0, reported by the agent as container_host). Every bridge network's containers reach it through
 *   their own gateway; the firewall accepts the port on the Docker bridges from the Docker ranges only.
 * - Other servers (native or containers, whose traffic is NATed to their server's address): the instance's server's
 *   private address on a network both share — a Kiln private network (WireGuard) first, else the provider private
 *   network, only where membership is known: both servers created by Kiln with the same credential, in the same
 *   region, of a provider that puts such servers on one private network by default (databases.key_value.
 *   provider_private_networks). A private IPv4 alone proves nothing (separate VPCs, regions, NATed custom servers: the
 *   address could be unreachable or another machine's, and the password would go there). Never the public address:
 *   without a shared private network the reference stays unresolved. The firewall accepts the port from those servers'
 *   addresses on that network only ("peers").
 *
 * The consumers are the sites placed in the instance's environment (references only resolve there), so the instance
 * listens on its private address only while a site of its environment runs on another server.
 */
final class KeyValueNetwork
{
    public const FEATURE = 'db.redis.network';

    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly PrivateNetwork $network,
        private readonly ProjectDirectory $projects,
        private readonly SiteDirectory $sites,
    ) {}

    /** The instance's server can listen beyond 127.0.0.1 (its agent supports db.redis.network). */
    public static function enabled(DatabaseServer $server): bool
    {
        return $server->engine->isKeyValue() && $server->container_access;
    }

    /** Docker ranges the server's containers connect from; empty when container access is configured off. */
    public static function containerRanges(): array
    {
        return array_values((array) config('databases.container_networks', []));
    }

    /**
     * The sites of the instance's environment, as database consumers.
     *
     * @return list<DatabaseConsumer>
     */
    public function consumers(Database $database): array
    {
        $placed = $this->projects->projectOf(ServiceKind::Database, $database->id);

        if ($placed === null) {
            return [];
        }

        $consumers = [];

        foreach ($this->projects->servicesIn($placed->environmentId) as $service) {
            if ($service->kind !== ServiceKind::Site || ($site = $this->sites->find($service->refId)) === null) {
                continue;
            }

            $consumers[] = new DatabaseConsumer($site->name, array_values(array_map('strtolower', $site->serverIds())), $site->runtime->usesDocker());
        }

        return $consumers;
    }

    /**
     * How $serverIds (servers other than the instance's) reach it: the instance server's address on the first network
     * all of them share with it, and each one's own address there (what the firewall lets in; containers are NATed to
     * it). Kiln private networks first (oldest of the instance's server), then the provider private network.
     *
     * @param  list<string>  $serverIds
     * @return array{host: ?string, via: ?string, interface: ?string, peers: array<string, string>, missing: list<string>} missing: servers
     *                                                                                                                     sharing no
     *                                                                                                                     private network
     *                                                                                                                     with it at all
     */
    public function reach(Database $database, array $serverIds): array
    {
        $serverIds = array_values(array_unique(array_diff(array_map('strtolower', $serverIds), [$database->server_id])));
        $candidates = $this->candidates($database->server_id, $serverIds);

        foreach ($candidates as $candidate) {
            if (count($candidate['peers']) === count($serverIds)) {
                return ['host' => $candidate['host'], 'via' => $candidate['via'], 'interface' => $candidate['interface'], 'peers' => $candidate['peers'], 'missing' => []];
            }
        }

        $covered = array_merge(...array_map(fn (array $c) => array_keys($c['peers']), $candidates ?: [['peers' => []]]));

        return ['host' => null, 'via' => null, 'interface' => null, 'peers' => [], 'missing' => array_values(array_diff($serverIds, $covered))];
    }

    /**
     * What the instance should listen on and let in.
     *
     * @return array{bind: list<string>, containers: bool, peers: list<string>, peer_interfaces: array<string, string>}
     *                                                                                                                  peer_interfaces: the WireGuard interface a peer
     *                                                                                                                  arrives on (the agent can't tell before the
     *                                                                                                                  interface exists)
     */
    public function desired(Database $database): array
    {
        $server = $database->databaseServer;

        if (! self::enabled($server)) {
            return ['bind' => ['127.0.0.1'], 'containers' => false, 'peers' => [], 'peer_interfaces' => []];
        }

        $bind = [];
        $peers = [];
        $interfaces = [];

        foreach ($this->consumers($database) as $consumer) {
            $others = array_values(array_diff($consumer->serverIds, [$database->server_id]));

            if ($others === []) {
                continue;
            }

            $reach = $this->reach($database, $consumer->serverIds);

            if ($reach['host'] !== null) {
                $bind[] = $reach['host'];
                array_push($peers, ...array_values($reach['peers']));

                foreach ($reach['interface'] !== null ? $reach['peers'] : [] as $address) {
                    $interfaces[$address] = $reach['interface'];
                }
            }
        }

        $bind = array_values(array_unique($bind));
        sort($bind);
        $peers = array_values(array_unique($peers));
        sort($peers);

        ksort($interfaces);

        return ['bind' => ['127.0.0.1', ...$bind], 'containers' => self::containerRanges() !== [], 'peers' => $peers, 'peer_interfaces' => $interfaces];
    }

    /**
     * Where the consumer reaches the instance: native on its server → 127.0.0.1; containers there → the Docker bridge
     * address the agent reported; any other server (native or containers) → the instance server's private address on a
     * network they share (WireGuard first, then the provider's). Never a public address. The address must be one the
     * instance listens on already (the agent reported it), else the reference waits for the re-apply.
     *
     * @return array{host: ?string, reason: ?string}
     */
    public function hostFor(Database $database, DatabaseConsumer $consumer): array
    {
        $engine = $database->databaseServer;
        $label = $engine->engine->label();
        $instance = "the {$label} instance {$database->name} on {$engine->server_name}";
        $elsewhere = array_values(array_diff(array_map('strtolower', $consumer->serverIds), [$database->server_id]));
        $network = (array) $database->network;
        $bound = (array) ($network['bind'] ?? ['127.0.0.1']);
        $update = "update the agent on {$engine->server_name} (Redis / Valkey network access needs agent support for db.redis.network)";

        if ($elsewhere === [] && ! $consumer->containerized) {
            return ['host' => '127.0.0.1', 'reason' => null];
        }

        if (! self::enabled($engine)) {
            $where = $elsewhere !== [] ? 'runs on '.$this->names($elsewhere) : 'runs in a container';

            return ['host' => null, 'reason' => "{$consumer->name} {$where}, but {$instance} only accepts connections from that server itself: {$update}."];
        }

        if ($elsewhere === []) {
            $host = $network['container_host'] ?? null;

            if ($host === null || self::containerRanges() === []) {
                return ['host' => null, 'reason' => "{$consumer->name} runs in a container, but {$instance} does not listen on the Docker bridge (docker0) yet: ".(self::containerRanges() === [] ? 'container access is turned off (KILN_DOCKER_NETWORKS).' : 'is Docker installed and running there? Kiln applies the instance again once it is.')];
            }

            return ['host' => $host, 'reason' => null];
        }

        $reach = $this->reach($database, $consumer->serverIds);

        if ($reach['host'] === null) {
            $missing = $reach['missing'] !== [] ? $reach['missing'] : $elsewhere;

            return ['host' => null, 'reason' => "{$consumer->name} runs on ".$this->names($missing).", which shares no private network with {$engine->server_name}, and {$instance} is never exposed on a public address. Add both servers to a private network (Network → Private networks)".($reach['missing'] === [] ? ' — one network that all of the site\'s servers share' : '').'.'];
        }

        if (! in_array($reach['host'], $bound, true)) {
            return ['host' => null, 'reason' => "{$consumer->name} runs on ".$this->names($elsewhere).", and {$instance} does not listen on {$reach['host']} ({$reach['via']}) yet: Kiln is applying it (a restart that keeps the data); deploy again once it is done."];
        }

        return ['host' => $reach['host'], 'reason' => null];
    }

    /** @param  list<string>  $serverIds */
    private function names(array $serverIds): string
    {
        return implode(', ', array_map(fn (string $id) => $this->servers->find($id)?->name ?? $id, $serverIds));
    }

    /**
     * @param  list<string>  $serverIds
     * @return list<array{host: string, via: string, interface: ?string, peers: array<string, string>}>
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
            $candidates[] = ['host' => $membership->address, 'via' => "private network {$membership->name}", 'interface' => $membership->interface, 'peers' => $peerNetworks[$membership->networkId] ?? []];
        }

        $instance = $this->servers->find($instanceServerId);

        if ($instance?->privateIpv4 !== null) {
            $peers = [];

            foreach ($serverIds as $id) {
                $server = $this->servers->find($id);

                if ($server?->privateIpv4 !== null && self::shareProviderNetwork($instance, $server)) {
                    $peers[$id] = $server->privateIpv4;
                }
            }

            $candidates[] = ['host' => $instance->privateIpv4, 'via' => 'provider private network', 'interface' => null, 'peers' => $peers];
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
            return (bool) config('databases.key_value.custom_private_network', false);
        }

        return in_array($a->provider, (array) config('databases.key_value.provider_private_networks', []), true)
            && $a->providerCredentialId !== null && $a->providerCredentialId === $b->providerCredentialId
            && $a->region !== null && $a->region === $b->region;
    }
}
