<?php

namespace Kiln\Network\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Network\Application\ApplyFirewalls;
use Kiln\Network\Domain\Models\PrivateNetwork;
use Kiln\Network\Domain\Models\PrivateNetworkMember;
use Kiln\Network\Events\PrivateNetworkChanged;

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
