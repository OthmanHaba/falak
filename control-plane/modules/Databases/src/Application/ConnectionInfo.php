<?php

namespace Kiln\Databases\Application;

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
    ) {}

    /**
     * @return array{engine: string, driver: string, port: int, hosts: list<array{label: string, value: string, hint: string}>}
     */
    public function for(DatabaseServer $server): array
    {
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
            'driver' => $server->engine->driver(),
            'port' => $server->port,
            'hosts' => $hosts,
        ];
    }
}
