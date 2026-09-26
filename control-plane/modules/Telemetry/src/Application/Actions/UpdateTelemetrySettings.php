<?php

namespace Kiln\Telemetry\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Telemetry\Contracts\TelemetryConfigurator;
use Kiln\Telemetry\Domain\Models\TelemetrySettings;

/**
 * Save an organization's telemetry overrides and push telemetry.configure to its servers.
 */
final class UpdateTelemetrySettings
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly TelemetryConfigurator $configurator,
    ) {}

    /**
     * @param  array{otlp_endpoint?: ?string, otlp_token?: ?string, clear_otlp_token?: bool, environment?: ?string, traces_ratio?: float|string|null, metrics_interval_s?: int|string|null}  $data
     * @return int servers reconfigured
     */
    public function __invoke(string $organizationId, array $data): int
    {
        $settings = TelemetrySettings::for($organizationId);

        $settings->fill([
            'otlp_endpoint' => ($data['otlp_endpoint'] ?? null) ?: null,
            'environment' => ($data['environment'] ?? null) ?: null,
            'traces_ratio' => isset($data['traces_ratio']) && $data['traces_ratio'] !== '' ? (float) $data['traces_ratio'] : null,
            'metrics_interval_s' => isset($data['metrics_interval_s']) && $data['metrics_interval_s'] !== '' ? (int) $data['metrics_interval_s'] : null,
        ]);

        $tokenChanged = false;

        if (! empty($data['clear_otlp_token'])) {
            $tokenChanged = $settings->otlp_token !== null;
            $settings->otlp_token = null;
        } elseif (($data['otlp_token'] ?? null) !== null && $data['otlp_token'] !== '') {
            $settings->otlp_token = $data['otlp_token'];
            $tokenChanged = true;
        }

        $changed = array_keys($settings->getDirty());
        $settings->save();

        $this->audit->record('telemetry.settings.updated', 'organization', $organizationId, [
            'changed' => array_values(array_diff($changed, ['otlp_token', 'updated_at', 'created_at'])),
            'bearer_changed' => $tokenChanged,
        ], $organizationId);

        return $this->configurator->reconfigureOrganization($organizationId);
    }
}
