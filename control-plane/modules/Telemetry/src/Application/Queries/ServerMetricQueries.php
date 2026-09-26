<?php

namespace Kiln\Telemetry\Application\Queries;

use Kiln\Telemetry\Contracts\PromQl;

/**
 * PromQL for the in-app server charts (host metrics from kiln-agent, OTel system.* semconv).
 */
final class ServerMetricQueries
{
    /** range => [seconds, step seconds] */
    public const RANGES = [
        '1h' => [3600, 30],
        '6h' => [21600, 120],
        '24h' => [86400, 300],
        '7d' => [604800, 1800],
    ];

    /**
     * @return array<string, string> chart key => PromQL
     */
    public static function for(string $serverId): array
    {
        $s = '{'.PromQl::label('kiln_server_id', strtoupper($serverId)).'}';

        return [
            'cpu' => "avg(system_cpu_utilization_ratio{$s}) * 100",
            'memory' => "avg(system_memory_utilization_ratio{$s}) * 100",
            'disk' => "max by (system_filesystem_mountpoint) (system_filesystem_utilization_ratio{$s}) * 100",
            'load1' => "avg(system_cpu_load_average_1m{$s})",
            'load5' => "avg(system_cpu_load_average_5m{$s})",
            'load15' => "avg(system_cpu_load_average_15m{$s})",
            'network' => "sum by (network_io_direction) (rate(system_network_io_bytes_total{$s}[5m]))",
            'disk_io' => "sum by (disk_io_direction) (rate(system_disk_io_bytes_total{$s}[5m]))",
        ];
    }
}
