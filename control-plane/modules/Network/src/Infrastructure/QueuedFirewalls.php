<?php

namespace Falak\Network\Infrastructure;

use Falak\Network\Application\ApplyFirewall;
use Falak\Network\Contracts\Firewalls;

final class QueuedFirewalls implements Firewalls
{
    public function __construct(private readonly ApplyFirewall $apply) {}

    public function converge(string $serverId): void
    {
        ($this->apply)(strtolower($serverId));
    }
}
