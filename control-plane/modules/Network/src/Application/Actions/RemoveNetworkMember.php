<?php

namespace Kiln\Network\Application\Actions;

use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Network\Application\ApplyFirewalls;
use Kiln\Network\Application\ConvergePrivateNetwork;
use Kiln\Network\Domain\Models\PrivateNetworkMember;
use Kiln\Network\Events\PrivateNetworkChanged;
use Kiln\Network\Infrastructure\WireGuardPayloads;

/**
 * Takes a server out of the mesh: tears its interface down (`state: absent`), then re-applies the
 * remaining members (dropping it as a peer) and every affected firewall.
 */
final class RemoveNetworkMember
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly WireGuardPayloads $payloads,
        private readonly ConvergePrivateNetwork $converge,
        private readonly ApplyFirewalls $firewalls,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  bool  $serverGone  the server itself was deleted: nothing to tear down on it
     */
    public function __invoke(PrivateNetworkMember $member, bool $serverGone = false): void
    {
        $network = $member->network;

        if (! $serverGone) {
            $this->tearDown($member);
        }

        $member->delete();

        ($this->converge)($network);
        ($this->firewalls)([...$network->members()->pluck('server_id'), ...($serverGone ? [] : [$member->server_id])]);

        $this->audit->record('network.private_network_member_removed', 'private_network', $network->id, [
            'server_id' => $member->server_id,
            'address' => $member->address,
        ], $network->organization_id);

        PrivateNetworkChanged::dispatch($network->id, $network->organization_id, PrivateNetworkChanged::MEMBER_REMOVED, [$member->server_id]);
    }

    public function tearDown(PrivateNetworkMember $member): void
    {
        try {
            $this->agents->dispatch(
                $member->server_id,
                'net.wireguard.apply',
                $this->payloads->absent($member->network, $member),
                (int) config('network.command_timeout', 120),
                "net.wireguard.absent:{$member->id}:".($member->revision + 1),
            );
        } catch (AgentUnavailable) {
            // The host is unreachable; its interface is removed with the machine or on the next re-provision.
        }
    }
}
