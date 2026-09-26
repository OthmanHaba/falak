<?php

namespace Kiln\Telemetry\Contracts;

use DateTimeInterface;

/**
 * Deep links into the in-app trace/log explorers (and Grafana Explore when configured).
 */
interface TelemetryLinks
{
    /** In-app trace timeline page. */
    public function trace(string $traceId): string;

    /**
     * In-app log explorer pre-filtered.
     *
     * @param  array{site_id?: string, server_id?: string, service?: string, trace_id?: string, search?: string}  $filters
     */
    public function logs(array $filters = [], ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): string;

    /** In-app trace search pre-filtered. */
    public function traceSearch(array $filters = [], ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): string;

    /** Grafana Explore URL for a trace, or null when Grafana is not configured. */
    public function grafanaTrace(string $traceId): ?string;

    /** Grafana dashboard URL (by base dashboard uid, e.g. "kiln-laravel") for the organization, or null. */
    public function grafanaDashboard(string $organizationId, string $dashboardUid, array $variables = []): ?string;
}
