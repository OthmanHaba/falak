<?php

namespace Kiln\Network\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Network\Application\Actions\RemoveNetworkMember;
use Kiln\Network\Domain\Models\FirewallRule;
use Kiln\Network\Domain\Models\FirewallState;
use Kiln\Network\Domain\Models\PrivateNetworkMember;
use Kiln\Servers\Events\ServerDeleted;

/**
 * Drops a deleted server's firewall and removes it from its private networks (re-applying the rest).
 */
final class ForgetDeletedServer implements ShouldQueue
{
    public function __construct(private readonly RemoveNetworkMember $remove) {}

    public function handle(ServerDeleted $event): void
    {
        FirewallRule::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->delete();
        FirewallState::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->delete();

        PrivateNetworkMember::query()
            ->with('network')
            ->where('server_id', $event->serverId)
            ->where('organization_id', $event->organizationId)
            ->get()
            ->each(fn (PrivateNetworkMember $member) => ($this->remove)($member, serverGone: true));
    }
}
