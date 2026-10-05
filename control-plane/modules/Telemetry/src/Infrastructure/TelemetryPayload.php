<?php

namespace Falak\Telemetry\Infrastructure;

use Falak\Telemetry\Contracts\Data\SiteTelemetryTarget;
use Falak\Telemetry\Domain\Models\TelemetrySettings;

/**
 * Builds the telemetry.configure payload. Ids are sent as canonical upper-case ULIDs, so every
 * signal carries upper-case falak.* resource attributes (queries normalize the same way).
 * (contracts/agent-protocol/commands/telemetry.configure.schema.json).
 */
final class TelemetryPayload
{
    /**
     * @param  list<SiteTelemetryTarget>  $sites
     * @return array<string, mixed>
     */
    public static function build(string $organizationId, string $serverId, TelemetrySettings $settings, array $sites): array
    {
        $payload = [
            'endpoint' => $settings->endpoint(),
            'resource' => [
                'org_id' => strtoupper($organizationId),
                'server_id' => strtoupper($serverId),
                'environment' => $settings->environmentName(),
            ],
            'sampling' => ['traces_ratio' => max(0.0, min(1.0, $settings->tracesRatio()))],
            'metrics' => ['enabled' => true, 'interval_s' => max(5, $settings->metricsInterval())],
            'insights' => ['enabled' => true],
        ];

        if ($token = $settings->token()) {
            $payload['headers'] = ['Authorization' => 'Bearer '.$token];
        }

        $siteEntries = [];
        $logSources = [];

        foreach ($sites as $site) {
            $siteEntries[] = array_filter([
                'slug' => $site->slug,
                'site_id' => strtoupper($site->siteId),
                'environment' => $site->environment,
                'deployment_id' => $site->deploymentId ? strtoupper($site->deploymentId) : null,
                'release_id' => $site->releaseId ? strtoupper($site->releaseId) : null,
            ], fn ($v) => $v !== null && $v !== '');

            foreach ($site->logSources as $source) {
                $logSources[] = array_filter([
                    'path' => $source['path'],
                    'service' => $source['service'] ?? null,
                    'site' => $site->slug,
                    'format' => $source['format'] ?? null,
                    'kind' => $source['kind'] ?? null,
                    'multiline' => $source['multiline'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
            }
        }

        if ($siteEntries !== []) {
            $payload['sites'] = $siteEntries;
        }

        if ($logSources !== []) {
            $payload['log_sources'] = $logSources;
        }

        return $payload;
    }
}
