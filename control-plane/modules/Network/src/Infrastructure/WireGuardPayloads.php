<?php

namespace Kiln\Network\Infrastructure;

use Kiln\Network\Domain\Enums\KeyStatus;
use Kiln\Network\Domain\Models\PrivateNetwork;
use Kiln\Network\Domain\Models\PrivateNetworkMember;
use Kiln\Servers\Contracts\ServerDirectory;

/**
 * Builds `net.wireguard.apply` and the key-delivery `system.write_file` payloads.
 */
final class WireGuardPayloads
{
    public function __construct(private readonly ServerDirectory $servers) {}

    public static function keyPath(PrivateNetwork $network): string
    {
        return "/etc/kiln/wireguard/{$network->interface}.key";
    }

    /**
     * Full mesh: every other member whose key is on its host is a peer.
     *
     * @return array<string, mixed>
     */
    public function apply(PrivateNetwork $network, PrivateNetworkMember $member): array
    {
        $peers = $network->members()
            ->where('id', '!=', $member->id)
            ->where('key_status', KeyStatus::Installed)
            ->get()
            ->map(function (PrivateNetworkMember $peer) use ($network) {
                $ipv4 = $this->servers->find($peer->server_id)?->ipv4;

                return array_filter([
                    'public_key' => $peer->public_key,
                    'allowed_ips' => ["{$peer->address}/32"],
                    'endpoint' => $ipv4 ? "{$ipv4}:{$network->listen_port}" : null,
                    'persistent_keepalive' => (int) config('network.persistent_keepalive', 25),
                ], fn ($value) => $value !== null);
            })
            ->values()
            ->all();

        return [
            'interface' => $network->interface,
            'address' => "{$member->address}/{$network->range()->prefix}",
            'listen_port' => $network->listen_port,
            'peers' => $peers,
            'state' => 'present',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function absent(PrivateNetwork $network, PrivateNetworkMember $member): array
    {
        return [
            'interface' => $network->interface,
            'address' => "{$member->address}/{$network->range()->prefix}",
            'listen_port' => $network->listen_port,
            'peers' => [],
            'state' => 'absent',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function keyFile(PrivateNetwork $network, string $privateKey): array
    {
        return [
            'path' => self::keyPath($network),
            'content' => $privateKey."\n",
            'mode' => '0600',
            'owner' => 'root',
            'group' => 'root',
            'create_dirs' => true,
            'state' => 'present',
        ];
    }
}
