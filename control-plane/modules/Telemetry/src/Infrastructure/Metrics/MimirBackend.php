<?php

namespace Kiln\Telemetry\Infrastructure\Metrics;

use Kiln\Telemetry\Infrastructure\HttpClient;

/**
 * Grafana Mimir (http://mimir:9009/prometheus); multi-tenant via X-Scope-OrgID.
 */
final class MimirBackend extends PrometheusHttpBackend
{
    public static function fromConfig(): self
    {
        $tenant = config('telemetry.metrics.tenant');

        return new self(new HttpClient(
            'Mimir',
            config('telemetry.metrics.query_url'),
            $tenant ? ['X-Scope-OrgID' => (string) $tenant] : [],
            config('telemetry.metrics.token') ?: null,
        ));
    }

    public function name(): string
    {
        return 'mimir';
    }
}
