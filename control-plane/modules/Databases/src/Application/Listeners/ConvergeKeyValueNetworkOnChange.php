<?php

namespace Falak\Databases\Application\Listeners;

use Falak\Databases\Application\KeyValue\ConvergeKeyValueNetwork;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Projects\Events\ServiceLinked;
use Falak\Projects\Events\ServiceUnlinked;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Sites\Events\SiteTargetsChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

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

    /**
     * Docker installed on an active server outside Falak (Falak only installs it with the server's provisioning): the
     * agent's facts (sent when they change) report its version.
     */
    public function agentFactsReported(AgentFactsReported $event): void
    {
        if ($event->serverId !== null && ! empty($event->facts['docker'])) {
            ($this->converge)($event->organizationId, $event->serverId, docker: true);
        }
    }
}
