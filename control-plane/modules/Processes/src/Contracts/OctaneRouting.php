<?php

namespace Kiln\Processes\Contracts;

use Kiln\Processes\Events\OctaneRoutingChanged;

/**
 * Whether the edge may reverse-proxy a site to its Octane server on a server.
 *
 * Processes supervises `<slug>.octane` on 127.0.0.1:<port> (the port Sites persisted in the site's Laravel
 * settings) and only reports it once a probe got an HTTP answer from it — so the edge never proxies to a port
 * nothing listens on: never-deployed sites (placeholder release), a missing Swoole extension or a crashing
 * first start keep being served directly by FrankenPHP / PHP-FPM. {@see OctaneRoutingChanged} fires when
 * the answer changes.
 */
interface OctaneRouting
{
    /** The port Octane was verified listening on, or null when the edge must serve the site directly. */
    public function listeningPort(string $siteId, string $serverId): ?int;
}
