<?php

namespace Falak\Telemetry\Infrastructure\Grafana;

/**
 * The three Falak datasources, mirroring observability/grafana/provisioning/datasources/falak.yaml
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
                'uid' => 'falak-metrics',
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
                        ['name' => 'trace_id', 'datasourceUid' => 'falak-tempo'],
                        ['name' => 'traceID', 'datasourceUid' => 'falak-tempo'],
                    ],
                ],
            ],
            [
                'uid' => 'falak-loki',
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
                        'datasourceUid' => 'falak-tempo',
                        'url' => '${__value.raw}',
                        'urlDisplayLabel' => 'View trace',
                    ]],
                ],
            ],
            [
                'uid' => 'falak-tempo',
                'name' => 'Tempo',
                'type' => 'tempo',
                'access' => 'proxy',
                'url' => (string) ($urls['tempo_url'] ?? ''),
                'jsonData' => [
                    'nodeGraph' => ['enabled' => true],
                    'search' => ['hide' => false],
                    'traceQuery' => ['timeShiftEnabled' => true, 'spanStartTimeShift' => '-30m', 'spanEndTimeShift' => '30m'],
                    'tracesToLogsV2' => [
                        'datasourceUid' => 'falak-loki',
                        'spanStartTimeShift' => '-5m',
                        'spanEndTimeShift' => '5m',
                        'filterByTraceID' => true,
                        'filterBySpanID' => false,
                        'customQuery' => false,
                        'tags' => [['key' => 'service.name', 'value' => 'service_name']],
                    ],
                    'tracesToMetrics' => [
                        'datasourceUid' => 'falak-metrics',
                        'spanStartTimeShift' => '-15m',
                        'spanEndTimeShift' => '15m',
                        'tags' => [
                            ['key' => 'service.name', 'value' => 'service'],
                            ['key' => 'falak.site.id', 'value' => 'falak_site_id'],
                        ],
                        'queries' => [
                            ['name' => 'Request rate', 'query' => 'sum(rate(traces_spanmetrics_calls_total{$__tags}[5m]))'],
                            ['name' => 'Error rate', 'query' => 'sum(rate(traces_spanmetrics_calls_total{$__tags, status_code="STATUS_CODE_ERROR"}[5m]))'],
                            ['name' => 'p95 latency', 'query' => 'histogram_quantile(0.95, sum by (le) (rate(traces_spanmetrics_latency_bucket{$__tags}[5m])))'],
                        ],
                    ],
                    'serviceMap' => ['datasourceUid' => 'falak-metrics'],
                    'lokiSearch' => ['datasourceUid' => 'falak-loki'],
                ],
            ],
        ];
    }
}
