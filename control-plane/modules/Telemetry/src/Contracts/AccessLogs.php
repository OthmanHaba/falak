<?php

namespace Kiln\Telemetry\Contracts;

use DateTimeInterface;
use Kiln\Telemetry\Contracts\Data\AccessLogEntry;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;

/**
 * A site's edge HTTP access log ("Network Logs"): one entry per request the edge served for the site — on its
 * servers, or on the load balancer in front of them — shipped by the agents to Loki as `kiln_log_kind="access"`
 * records of the site (`service_name` = slug).
 */
interface AccessLogs
{
    /**
     * Newest first, from $from up to $to (exclusive): page with the last entry's `at()` as the next `$to`.
     *
     * Filters: `server_id`; `deployment_id` (requests served while that deployment's release was live on the server);
     * `method`; `status` — an exact code (404) or a class ("5xx"); `path` — substring of the request URI; `client_ip`.
     *
     * @param  array{server_id?: ?string, deployment_id?: ?string, method?: ?string, status?: int|string|null, path?: ?string, client_ip?: ?string}  $filters
     * @return list<AccessLogEntry>
     *
     * @throws TelemetryUnavailable
     * @throws TelemetryQueryFailed
     * @throws \InvalidArgumentException for an invalid status filter
     */
    public function forSite(string $organizationId, string $siteId, DateTimeInterface $from, DateTimeInterface $to, array $filters = [], int $limit = 200): array;
}
