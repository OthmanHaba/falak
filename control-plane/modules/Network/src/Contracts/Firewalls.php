<?php

namespace Falak\Network\Contracts;

/**
 * Lets other modules ask for a server's firewall to be re-applied after something it depends on changed
 * (e.g. its {@see WebOriginPolicy}).
 */
interface Firewalls
{
    public function converge(string $serverId): void;
}
