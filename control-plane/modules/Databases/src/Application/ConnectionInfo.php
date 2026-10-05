<?php

namespace Kiln\Databases\Application;

use Kiln\Databases\Application\KeyValue\KeyValueNetwork;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Network\Contracts\PrivateNetwork;
use Kiln\Servers\Contracts\ServerDirectory;

/**
 * Hosts an application can reach the engine on, most private first.
 */
final class ConnectionInfo
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly PrivateNetwork $network,
        private readonly KeyValueNetwork $keyValue,
    ) {}

    /**
     * @param  ?Database  $database  a Redis / Valkey instance: its own port; `access` lists who connects from where
     * @return array{engine: string, kind: string, driver: string, port: int, hosts: list<array{label: string, value: string, hint: string}>, access?: list<array{name: string, host: ?string, reason: ?string}>}
     */
    public function for(DatabaseServer $server, ?Database $database = null): array
    {
        if ($server->engine->isKeyValue()) {
            return $this->keyValue($server, $database);
        }

        $data = $this->servers->find($server->server_id);
        $hosts = [];

        if (! $server->dedicated) {
            $hosts[] = ['label' => 'Same server', 'value' => '127.0.0.1', 'hint' => "Sites hosted on {$server->server_name}."];
        }

        if ($address = $this->network->addressOf($server->server_id)) {
            $hosts[] = ['label' => 'Private network', 'value' => $address, 'hint' => 'WireGuard mesh address; reachable from servers in the same private network.'];
        }

        if ($data?->privateIpv4) {
            $hosts[] = ['label' => 'Provider private IP', 'value' => $data->privateIpv4, 'hint' => "Provider VPC address; open port {$server->port} to it in the firewall."];
        }

        if ($data?->ipv4) {
            $hosts[] = ['label' => 'Public IP', 'value' => $data->ipv4, 'hint' => "Needs port {$server->port} opened in the firewall; prefer a private address."];
        }

        return [
            'engine' => $server->engine->value,
            'kind' => $server->engine->kind()->value,
            'driver' => $server->engine->driver(),
            'port' => $server->port,
            'hosts' => $hosts,
        ];
    }

    /**
     * Redis / Valkey: the addresses the instance listens on (as the agent reported them) and, per site of its
     * environment, the host its references resolve to or why they can't.
     *
     * @return array{engine: string, kind: string, driver: string, port: int, hosts: list<array{label: string, value: string, hint: string}>, access: list<array{name: string, host: ?string, reason: ?string}>}
     */
    private function keyValue(DatabaseServer $server, ?Database $database): array
    {
        $network = (array) ($database?->network ?? []);
        $enabled = KeyValueNetwork::enabled($server);
        $hosts = [['label' => 'Same server', 'value' => '127.0.0.1', 'hint' => $enabled
            ? "Sites hosted natively on {$server->server_name}."
            : "Sites hosted natively on {$server->server_name}. Containers and other servers need a newer agent on {$server->server_name}."]];

        if ($containerHost = $network['container_host'] ?? null) {
            $hosts[] = ['label' => 'Containers', 'value' => $containerHost, 'hint' => "Docker sites, compose stacks and functions on {$server->server_name} (the Docker bridge; only the Docker ranges reach it)."];
        }

        $data = $this->servers->find($server->server_id);

        foreach ((array) ($network['bind'] ?? []) as $address) {
            if ($address === '127.0.0.1' || $address === $containerHost) {
                continue;
            }

            $wireGuard = collect($this->network->networksOf($server->server_id))->first(fn ($m) => $m->address === $address);
            $hosts[] = $wireGuard !== null
                ? ['label' => 'Private network', 'value' => $address, 'hint' => "WireGuard network {$wireGuard->name}: only the servers of this environment's sites get through the firewall."]
                : ['label' => $address === $data?->privateIpv4 ? 'Provider private IP' : 'Private address', 'value' => $address, 'hint' => "Provider private network: only the servers of this environment's sites get through the firewall."];
        }

        $access = [];

        if ($database !== null) {
            foreach ($this->keyValue->consumers($database) as $consumer) {
                $resolved = $this->keyValue->hostFor($database, $consumer);
                $access[] = ['name' => $consumer->name, 'host' => $resolved['host'], 'reason' => $resolved['reason']];
            }
        }

        return [
            'engine' => $server->engine->value,
            'kind' => $server->engine->kind()->value,
            'driver' => $server->engine->driver(),
            'port' => (int) ($database?->port ?? $server->port),
            'hosts' => $hosts,
            'access' => $access,
        ];
    }
}
