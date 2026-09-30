<?php

namespace Kiln\Network\Infrastructure;

use Kiln\Network\Application\ApplyFirewall;
use Kiln\Network\Contracts\Firewalls;

final class QueuedFirewalls implements Firewalls
{
    public function __construct(private readonly ApplyFirewall $apply) {}

    public function converge(string $serverId): void
    {
        ($this->apply)(strtolower($serverId));
    }
}
