<?php

return [
    /*
     * OTLP/HTTP ingress of the observability box (observability/gateway). Agents relay every signal
     * here; organizations may override the endpoint/token in Telemetry settings.
     */
    'otlp' => [
        'endpoint' => env('KILN_OTLP_ENDPOINT') ?: config('fleet.otlp_endpoint', 'http://localhost:4318'),
        'token' => env('KILN_OTLP_TOKEN'),
    ],

    'metrics' => [
        // victoriametrics | mimir
        'backend' => env('KILN_METRICS_BACKEND', 'victoriametrics'),
        // http://victoriametrics:8428 or http://mimir:9009/prometheus (or the gateway :9090/prometheus)
        'query_url' => env('KILN_METRICS_QUERY_URL'),
        // Mimir tenant (X-Scope-OrgID)
        'tenant' => env('KILN_MIMIR_TENANT'),
        'token' => env('KILN_METRICS_TOKEN'),
    ],

    'loki' => [
        'url' => env('KILN_LOKI_URL'),
        'tenant' => env('KILN_LOKI_TENANT'),
    ],

    'tempo' => [
        'url' => env('KILN_TEMPO_URL'),
    ],

    'grafana' => [
        // Reachable from the control plane (API calls).
        'url' => env('KILN_GRAFANA_URL'),
        // Reachable from browsers (deep links); defaults to url.
        'public_url' => env('KILN_GRAFANA_PUBLIC_URL'),
        // Service account token (Admin / Editor with folders + datasources + annotations).
        'token' => env('KILN_GRAFANA_TOKEN'),
        'dashboards_path' => env('KILN_GRAFANA_DASHBOARDS_PATH', base_path('../observability/grafana/dashboards')),
        // Datasource URLs as Grafana (not the control plane) reaches them.
        'datasources' => [
            'metrics_url' => env('KILN_GRAFANA_METRICS_URL', 'http://gateway:9090/prometheus'),
            'loki_url' => env('KILN_GRAFANA_LOKI_URL', 'http://loki:3100'),
            'tempo_url' => env('KILN_GRAFANA_TEMPO_URL', 'http://tempo:3200'),
        ],
    ],

    'http' => [
        'timeout' => (int) env('KILN_TELEMETRY_HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('KILN_TELEMETRY_HTTP_CONNECT_TIMEOUT', 3),
    ],

    // telemetry.configure is sent this long after an agent enrolls (or right after provisioning).
    'configure_delay_seconds' => 30,

    // Defaults for organizations without Telemetry settings.
    'defaults' => [
        'environment' => env('KILN_TELEMETRY_ENVIRONMENT', 'production'),
        'traces_ratio' => (float) env('KILN_TRACES_SAMPLE_RATIO', 1.0),
        'metrics_interval_s' => 15,
    ],
];
