<?php

namespace Falak\Network\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Application\ApplyFirewalls;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Network\Events\PrivateNetworkChanged;

final class DeletePrivateNetwork
{
    public function __construct(
        private readonly RemoveNetworkMember $remove,
        private readonly ApplyFirewalls $firewalls,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(PrivateNetwork $network): void
    {
        $members = $network->members()->get();

        $members->each(function (PrivateNetworkMember $member) use ($network) {
            $member->setRelation('network', $network);
            $this->remove->tearDown($member);
        });

        $serverIds = $members->pluck('server_id')->values()->all();

        $network->delete();

        ($this->firewalls)($serverIds);

        $this->audit->record('network.private_network_deleted', 'private_network', $network->id, [
            'name' => $network->name,
            'cidr' => $network->cidr,
            'servers' => $serverIds,
        ], $network->organization_id);

        PrivateNetworkChanged::dispatch($network->id, $network->organization_id, PrivateNetworkChanged::DELETED, $serverIds);
    }
}
