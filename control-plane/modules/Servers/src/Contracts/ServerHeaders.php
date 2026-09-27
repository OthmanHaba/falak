<?php

namespace Kiln\Servers\Contracts;

/**
 * The `server` prop every /servers/{id}/{tab} Inertia page passes to the shared ServerLayout
 * (resources/js/layouts/server-layout.tsx), so other modules' server tabs (Firewall, Private network,
 * Terminal, Recipes …) render the same header. Callers authorize access to the server first.
 */
interface ServerHeaders
{
    /**
     * @return array{id: string, name: string, type: string, type_label: string, status: string, status_message: ?string, provider: string, provider_label: string, region: ?string, ipv4: ?string, agent: ?array{status: string, last_heartbeat_at: ?string}}
     */
    public function for(string $serverId): array;
}
