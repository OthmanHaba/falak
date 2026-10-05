<?php

namespace Falak\Telemetry\Infrastructure;

use DateTimeInterface;
use Falak\Telemetry\Contracts\TelemetryLinks;
use Falak\Telemetry\Infrastructure\Grafana\GrafanaNames;

/**
 * Relative in-app URLs (usable as Inertia links) plus absolute Grafana URLs.
 */
final class DefaultTelemetryLinks implements TelemetryLinks
{
    private const LOG_FILTERS = ['site_id', 'server_id', 'service', 'trace_id', 'search'];

    private const TRACE_FILTERS = ['site_id', 'service', 'name', 'status', 'min_duration_ms'];

    public function trace(string $traceId): string
    {
        return route('observability.traces.show', ['traceId' => strtolower($traceId)], false);
    }

    public function logs(array $filters = [], ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): string
    {
        return route('observability.logs', $this->query($filters, self::LOG_FILTERS, $from, $to), false);
    }

    public function traceSearch(array $filters = [], ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): string
    {
        return route('observability.traces.index', $this->query($filters, self::TRACE_FILTERS, $from, $to), false);
    }

    public function grafanaTrace(string $traceId): ?string
    {
        $base = $this->grafanaBase();

        if ($base === null) {
            return null;
        }

        $panes = ['a' => [
            'datasource' => 'falak-tempo',
            'queries' => [['refId' => 'A', 'datasource' => ['type' => 'tempo', 'uid' => 'falak-tempo'], 'queryType' => 'traceql', 'query' => strtolower($traceId)]],
            'range' => ['from' => 'now-24h', 'to' => 'now'],
        ]];

        return $base.'/explore?'.http_build_query(['schemaVersion' => 1, 'panes' => json_encode($panes, JSON_UNESCAPED_SLASHES)]);
    }

    public function grafanaDashboard(string $organizationId, string $dashboardUid, array $variables = []): ?string
    {
        $base = $this->grafanaBase();

        if ($base === null) {
            return null;
        }

        $query = [];

        foreach ($variables as $name => $value) {
            $query['var-'.$name] = $value;
        }

        $uid = GrafanaNames::dashboardUid($dashboardUid, $organizationId);

        return $base.'/d/'.rawurlencode($uid).($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private function query(array $filters, array $allowed, ?DateTimeInterface $from, ?DateTimeInterface $to): array
    {
        $query = [];

        foreach ($allowed as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = (string) $filters[$key];
            }
        }

        if ($from) {
            $query['from'] = $from->format(DATE_ATOM);
        }

        if ($to) {
            $query['to'] = $to->format(DATE_ATOM);
        }

        return $query;
    }

    private function grafanaBase(): ?string
    {
        $url = config('telemetry.grafana.public_url') ?: config('telemetry.grafana.url');

        return $url ? rtrim((string) $url, '/') : null;
    }
}
