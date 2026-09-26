<?php

namespace Kiln\Edge\Contracts;

use Kiln\Edge\Contracts\Data\DomainData;
use Kiln\Fleet\Contracts\Data\CommandHandle;

/**
 * Edge (Caddy / FrankenPHP) routing for other modules.
 */
interface EdgeRoutes
{
    /**
     * The full edge.caddy.apply payload for a server, compiled from every site routed through it
     * (sites targeting it and sites it load-balances). Always valid against the command schema.
     *
     * @return array<string, mixed>
     */
    public function compile(string $serverId): array;

    /**
     * Compile and dispatch edge.caddy.apply now. Returns null when the server already runs (or is about
     * to run) an identical config and $force is false.
     */
    public function apply(string $serverId, bool $force = false): ?CommandHandle;

    /** Queue a debounced apply for each server (coalesces bursts of changes into one command). */
    public function schedule(string ...$serverIds): void;

    /**
     * Record the upstream a site's container now listens on for a server (from the deploy.container.swap
     * result), so every later compiled config keeps it. Schedules an apply when it changed.
     */
    public function recordUpstream(string $siteId, string $serverId, string $upstream): void;

    /**
     * The edge.caddy.apply route id of a site (deploy.container.swap `edge_route_id`).
     */
    public function routeId(string $siteId): string;

    /**
     * @return list<DomainData>
     */
    public function domainsFor(string $siteId): array;

    /**
     * TLS used for hosted test domains (<slug>.<KILN_TEST_DOMAIN>): Auto (ACME) or Internal.
     */
    public function testDomainTls(): TlsMode;
}
