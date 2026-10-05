<?php

namespace Falak\Telemetry\Infrastructure\Metrics;

use Falak\Telemetry\Infrastructure\HttpClient;

/**
 * VictoriaMetrics single-node (http://victoriametrics:8428).
 */
final class VictoriaMetricsBackend extends PrometheusHttpBackend
{
    public static function fromConfig(): self
    {
        return new self(new HttpClient('VictoriaMetrics', config('telemetry.metrics.query_url'), [], config('telemetry.metrics.token') ?: null));
    }

    public function name(): string
    {
        return 'victoriametrics';
    }
}
