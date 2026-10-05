<?php

namespace Falak\Network\Infrastructure;

use Falak\Network\Contracts\Data\PrivateNetworkMembership;
use Falak\Network\Contracts\PrivateNetwork;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Models\PrivateNetworkMember;

final class EloquentPrivateNetwork implements PrivateNetwork
{
    public function addressOf(string $serverId, ?string $networkId = null): ?string
    {
        return PrivateNetworkMember::query()
            ->where('server_id', $serverId)
            ->when($networkId !== null, fn ($q) => $q->where('network_id', $networkId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('address');
    }

    public function networksOf(string $serverId): array
    {
        return PrivateNetworkMember::query()
            ->with('network')
            ->where('server_id', $serverId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (PrivateNetworkMember $member) => new PrivateNetworkMembership(
                networkId: $member->network_id,
                name: $member->network->name,
                interface: $member->network->interface,
                address: $member->address,
                cidr: $member->network->cidr,
                applied: $member->status === ApplyStatus::Applied,
            ))
            ->values()
            ->all();
    }
}
