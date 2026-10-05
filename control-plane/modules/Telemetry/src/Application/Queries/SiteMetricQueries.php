<?php

namespace Falak\Telemetry\Application\Queries;

use Falak\Telemetry\Contracts\PromQl;

/**
 * PromQL for a site's Metrics tab: host CPU/memory of the servers it runs on (per server) and request rate,
 * errors and p95 latency from Tempo span metrics (`falak_site_id`), matching the falak-laravel-site dashboard.
 */
final class SiteMetricQueries
{
    /**
     * @param  list<string>  $serverIds
     * @return array<string, string> chart key => PromQL
     */
    public static function for(string $siteId, array $serverIds): array
    {
        $site = PromQl::label('falak_site_id', strtoupper($siteId)).',falak_event_type="request"';
        $queries = [
            'requests' => "sum(rate(traces_spanmetrics_calls_total{{$site}}[5m]))",
            'errors' => "sum(rate(traces_spanmetrics_calls_total{{$site},http_response_status_code=~\"5..\"}[5m]))",
            'p95' => "histogram_quantile(0.95, sum by (le) (rate(traces_spanmetrics_latency_bucket{{$site}}[5m]))) * 1000",
        ];

        if ($serverIds !== []) {
            $pattern = implode('|', array_map(fn (string $id) => preg_quote(strtoupper($id), '/'), $serverIds));
            $servers = 'falak_server_id=~'.PromQl::quote($pattern);
            $queries['cpu'] = "avg by (falak_server_id) (system_cpu_utilization_ratio{{$servers}}) * 100";
            $queries['memory'] = "avg by (falak_server_id) (system_memory_utilization_ratio{{$servers}}) * 100";
        }

        return $queries;
    }
}
