<?php

return [
    // Default address space for new private (WireGuard) networks.
    'private_cidr' => env('KILN_PRIVATE_NETWORK_CIDR', '10.90.0.0/24'),

    // Default WireGuard listen port for new private networks.
    'wireguard_port' => (int) env('KILN_WIREGUARD_PORT', 51820),

    'persistent_keepalive' => 25,

    // Always accepted by the agent to avoid lock-out (net.firewall.apply ssh_port).
    'ssh_port' => 22,

    'command_timeout' => 120,
];
