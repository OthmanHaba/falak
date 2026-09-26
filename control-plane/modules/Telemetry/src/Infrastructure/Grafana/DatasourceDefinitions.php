<?php

namespace Kiln\Telemetry\Infrastructure\Grafana;

/**
 * The three Kiln datasources, mirroring observability/grafana/provisioning/datasources/kiln.yaml
 * (uids are stable so dashboards and alert rules resolve them unchanged).
 */
final class DatasourceDefinitions
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $urls = (array) config('telemetry.grafana.datasources');
        $prometheusType = config('telemetry.metrics.backend') === 'mimir' ? 'Mimir' : 'Prometheus';

        return [
            [
                'uid' => 'kiln-metrics',
                'name' => 'Metrics',
                'type' => 'prometheus',
                'access' => 'proxy',
                'url' => (string) ($urls['metrics_url'] ?? ''),
                'isDefault' => true,
                'jsonData' => [
                    'prometheusType' => $prometheusType,
                    'timeInterval' => '15s',
                    'httpMethod' => 'POST',
                    'exemplarTraceIdDestinations' => [
                        ['name' => 'trace_id', 'datasourceUid' => 'kiln-tempo'],
                        ['name' => 'traceID', 'datasourceUid' => 'kiln-tempo'],
                    ],
                ],
            ],
            [
                'uid' => 'kiln-loki',
                'name' => 'Loki',
                'type' => 'loki',
                'access' => 'proxy',
                'url' => (string) ($urls['loki_url'] ?? ''),
                'jsonData' => [
                    'maxLines' => 1000,
                    'derivedFields' => [[
                        'name' => 'TraceID',
                        'matcherType' => 'label',
                        'matcherRegex' => 'trace_id',
                        'datasourceUid' => 'kiln-tempo',
                        'url' => '${__value.raw}',
                        'urlDisplayLabel' => 'View trace',
                    ]],
                ],
            ],
            [
                'uid' => 'kiln-tempo',
                'name' => 'Tempo',
                'type' => 'tempo',
                'access' => 'proxy',
                'url' => (string) ($urls['tempo_url'] ?? ''),
                'jsonData' => [
                    'nodeGraph' => ['enabled' => true],
                    'search' => ['hide' => false],
                    'traceQuery' => ['timeShiftEnabled' => true, 'spanStartTimeShift' => '-30m', 'spanEndTimeShift' => '30m'],
                    'tracesToLogsV2' => [
                        'datasourceUid' => 'kiln-loki',
                        'spanStartTimeShift' => '-5m',
                        'spanEndTimeShift' => '5m',
                        'filterByTraceID' => true,
                        'filterBySpanID' => false,
                        'customQuery' => false,
                        'tags' => [['key' => 'service.name', 'value' => 'service_name']],
                    ],
                    'tracesToMetrics' => [
                        'datasourceUid' => 'kiln-metrics',
                        'spanStartTimeShift' => '-15m',
                        'spanEndTimeShift' => '15m',
                        'tags' => [
                            ['key' => 'service.name', 'value' => 'service'],
                            ['key' => 'kiln.site.id', 'value' => 'kiln_site_id'],
                        ],
                        'queries' => [
                            ['name' => 'Request rate', 'query' => 'sum(rate(traces_spanmetrics_calls_total{$__tags}[5m]))'],
                            ['name' => 'Error rate', 'query' => 'sum(rate(traces_spanmetrics_calls_total{$__tags, status_code="STATUS_CODE_ERROR"}[5m]))'],
                            ['name' => 'p95 latency', 'query' => 'histogram_quantile(0.95, sum by (le) (rate(traces_spanmetrics_latency_bucket{$__tags}[5m])))'],
                        ],
                    ],
                    'serviceMap' => ['datasourceUid' => 'kiln-metrics'],
                    'lokiSearch' => ['datasourceUid' => 'kiln-loki'],
                ],
            ],
        ];
    }
}
