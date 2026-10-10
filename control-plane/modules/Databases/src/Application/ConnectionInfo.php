<?php

namespace Falak\Databases\Application;

use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Servers\Contracts\ServerDirectory;

/**
 * Where applications reach a database container, most private first, and who of its environment connects how.
 */
final class ConnectionInfo
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly InstanceNetwork $network,
    ) {}

    /**
     * @return array{engine: string, kind: string, driver: string, port: int, host_port: ?int, hosts: list<array{label: string, value: string, port: int, hint: string}>, access: list<array{name: string, host: ?string, port: int, reason: ?string}>}
     */
    public function for(DatabaseInstance $instance): array
    {
        $hosts = [];

        if ($instance->network() !== null) {
            $hosts[] = ['label' => 'Internal', 'value' => $instance->hostname, 'port' => $instance->port, 'hint' => "Containers of this environment on {$instance->server_name} (Docker sites, compose stacks), on its private Docker network."];
        }

        if ($instance->host_port !== null) {
            $hosts[] = ['label' => 'Same server', 'value' => '127.0.0.1', 'port' => $instance->host_port, 'hint' => "Sites running natively on {$instance->server_name}."];

            foreach ((array) ($instance->published_addresses ?? []) as $address) {
                $hosts[] = ['label' => 'Private network', 'value' => $address, 'port' => $instance->host_port, 'hint' => "Servers of this environment that share a private network with {$instance->server_name}."];
            }

            if ($instance->public_access && ($public = $this->servers->find($instance->server_id)?->ipv4) !== null) {
                $hosts[] = ['label' => 'Public', 'value' => $public, 'port' => $instance->host_port, 'hint' => 'Public access is on: TLS is required. Restrict who reaches the port in the server firewall.'];
            }
        }

        $access = [];

        foreach ($this->network->consumers($instance) as $consumer) {
            $resolved = $this->network->hostFor($instance, $consumer);
            $access[] = ['name' => $consumer->name, 'host' => $resolved['host'], 'port' => $resolved['port'], 'reason' => $resolved['reason']];
        }

        return [
            'engine' => $instance->engine->value,
            'kind' => $instance->engine->kind()->value,
            'driver' => $instance->engine->driver(),
            'port' => $instance->port,
            'host_port' => $instance->host_port,
            'hosts' => $hosts,
            'access' => $access,
        ];
    }
}
