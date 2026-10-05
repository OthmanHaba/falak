<?php

namespace Falak\Network\Application;

/**
 * Re-converges the firewalls of several servers (private-network membership changes open or close
 * WireGuard ports on every member).
 */
final class ApplyFirewalls
{
    public function __construct(private readonly ApplyFirewall $apply) {}

    /**
     * @param  iterable<string>  $serverIds
     */
    public function __invoke(iterable $serverIds): void
    {
        foreach (array_unique([...$serverIds]) as $serverId) {
            ($this->apply)($serverId);
        }
    }
}
