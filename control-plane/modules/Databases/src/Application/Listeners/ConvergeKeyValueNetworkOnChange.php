<?php

namespace Kiln\Databases\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Databases\Application\KeyValue\ConvergeKeyValueNetwork;
use Kiln\Network\Events\PrivateNetworkChanged;
use Kiln\Projects\Events\ServiceLinked;
use Kiln\Projects\Events\ServiceUnlinked;
use Kiln\Servers\Events\ServerProvisioned;
use Kiln\Sites\Events\SiteTargetsChanged;

/**
 * Who uses a Redis / Valkey instance, or how servers reach each other, changed: its bind addresses and firewall peers
 * follow (ConvergeKeyValueNetwork).
 */
final class ConvergeKeyValueNetworkOnChange implements ShouldQueue
{
    public function __construct(private readonly ConvergeKeyValueNetwork $converge) {}

    public function serviceLinked(ServiceLinked $event): void
    {
        ($this->converge)($event->organizationId);
    }

    public function serviceUnlinked(ServiceUnlinked $event): void
    {
        ($this->converge)($event->organizationId);
    }

    public function siteTargetsChanged(SiteTargetsChanged $event): void
    {
        ($this->converge)($event->organizationId);
    }

    public function privateNetworkChanged(PrivateNetworkChanged $event): void
    {
        ($this->converge)($event->organizationId);
    }

    /** Docker may have arrived with the server's (re-)provisioning: instances without the bridge listen on it. */
    public function serverProvisioned(ServerProvisioned $event): void
    {
        ($this->converge)($event->organizationId, $event->serverId, docker: true);
    }
}
