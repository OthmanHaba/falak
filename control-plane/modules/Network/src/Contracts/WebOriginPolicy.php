<?php

namespace Kiln\Network\Contracts;

/**
 * Who may reach a server's web ports (TCP 80 / 443), decided by another module (Edge, for servers behind Cloudflare).
 * The firewall compiler applies it on top of the server's own rules.
 */
interface WebOriginPolicy
{
    /**
     * null: the server's rules as they are.
     * ['mode' => 'closed']: no inbound web traffic at all (the server is reached through a tunnel).
     * ['mode' => 'only', 'sources' => [cidr, …]]: web ports only from these ranges (e.g. Cloudflare's).
     *
     * @return array{mode: 'closed'}|array{mode: 'only', sources: list<string>}|null
     */
    public function for(string $serverId): ?array;
}
