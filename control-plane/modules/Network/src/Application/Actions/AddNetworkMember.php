<?php

namespace Falak\Network\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Application\ApplyFirewalls;
use Falak\Network\Application\ConvergePrivateNetwork;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Enums\KeyStatus;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Network\Infrastructure\WireGuardKeys;
use Falak\Servers\Contracts\Data\ServerData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Joins a server to the mesh: allocates the lowest free address, generates its X25519 key pair,
 * delivers the private key to the host, then re-applies every member and their firewalls.
 */
final class AddNetworkMember
{
    public function __construct(
        private readonly WireGuardKeys $keys,
        private readonly ConvergePrivateNetwork $converge,
        private readonly ApplyFirewalls $firewalls,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(PrivateNetwork $network, ServerData $server): PrivateNetworkMember
    {
        if ($server->organizationId !== $network->organization_id) {
            throw ValidationException::withMessages(['server_id' => 'The server does not belong to this organization.']);
        }

        if (! $server->isActive()) {
            throw ValidationException::withMessages(['server_id' => 'Only active servers can join a private network.']);
        }

        $clash = PrivateNetworkMember::query()
            ->with('network')
            ->where('server_id', $server->id)
            ->where('network_id', '!=', $network->id)
            ->get()
            ->first(fn (PrivateNetworkMember $m) => $m->network->listen_port === $network->listen_port);

        if ($clash) {
            throw ValidationException::withMessages(['server_id' => "{$server->name} already uses UDP port {$network->listen_port} for the {$clash->network->name} network."]);
        }

        $member = DB::transaction(function () use ($network, $server) {
            PrivateNetwork::query()->whereKey($network->id)->lockForUpdate()->first();

            if ($network->members()->where('server_id', $server->id)->exists()) {
                throw ValidationException::withMessages(['server_id' => "{$server->name} is already in this network."]);
            }

            $address = $network->range()->firstFree($network->members()->pluck('address')->all());

            if ($address === null) {
                throw ValidationException::withMessages(['server_id' => "The network {$network->cidr} has no free addresses left."]);
            }

            $keys = $this->keys->generate();

            return PrivateNetworkMember::query()->create([
                'network_id' => $network->id,
                'organization_id' => $network->organization_id,
                'server_id' => $server->id,
                'address' => $address,
                'public_key' => $keys['public'],
                'private_key' => $keys['private'],
                'key_status' => KeyStatus::Pending,
                'status' => ApplyStatus::Pending,
                'revision' => 0,
            ]);
        });

        $this->converge->installKey($network, $member);
        ($this->firewalls)($network->members()->pluck('server_id'));

        $this->audit->record('network.private_network_member_added', 'private_network', $network->id, [
            'server_id' => $server->id,
            'server' => $server->name,
            'address' => $member->address,
            'public_key' => $member->public_key,
        ], $network->organization_id);

        PrivateNetworkChanged::dispatch($network->id, $network->organization_id, PrivateNetworkChanged::MEMBER_ADDED, [$server->id]);

        return $member->refresh();
    }
}
