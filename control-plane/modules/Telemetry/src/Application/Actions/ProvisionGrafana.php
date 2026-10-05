<?php

namespace Falak\Telemetry\Application\Actions;

use JsonException;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Falak\Telemetry\Domain\Models\GrafanaState;
use Falak\Telemetry\Infrastructure\Grafana\DatasourceDefinitions;
use Falak\Telemetry\Infrastructure\Grafana\GrafanaClient;
use Falak\Telemetry\Infrastructure\Grafana\GrafanaNames;
use RuntimeException;
use Throwable;

/**
 * Provision Grafana for an organization: shared Falak datasources, the organization's folder and
 * every dashboard from observability/grafana/dashboards imported into it (per-organization uids).
 */
final class ProvisionGrafana
{
    public function __construct(
        private readonly GrafanaClient $grafana,
        private readonly OrganizationDirectory $organizations,
    ) {}

    /**
     * @return array<string, string> base dashboard uid => imported uid
     *
     * @throws TelemetryUnavailable when Grafana is not configured
     * @throws Throwable on API failures (recorded in telemetry_grafana_states.last_error)
     */
    public function __invoke(string $organizationId): array
    {
        if (! $this->grafana->configured()) {
            throw TelemetryUnavailable::notConfigured('Grafana');
        }

        $organization = $this->organizations->find($organizationId)
            ?? throw new RuntimeException("Organization [{$organizationId}] does not exist.");

        $state = GrafanaState::query()->firstOrNew(['organization_id' => $organizationId]);

        try {
            foreach (DatasourceDefinitions::all() as $datasource) {
                $this->grafana->upsertDatasource($datasource);
            }

            $folderUid = GrafanaNames::folderUid($organizationId);
            $this->grafana->ensureFolder($folderUid, $organization->name);

            $imported = [];

            foreach ($this->dashboards() as $dashboard) {
                $baseUid = (string) $dashboard['uid'];
                $dashboard['uid'] = GrafanaNames::dashboardUid($baseUid, $organizationId);
                $dashboard['title'] = $organization->name.' · '.(string) ($dashboard['title'] ?? $baseUid);
                $this->grafana->importDashboard($dashboard, $folderUid);
                $imported[$baseUid] = $dashboard['uid'];
            }

            $state->forceFill([
                'folder_uid' => $folderUid,
                'dashboards' => $imported,
                'provisioned_at' => now(),
                'last_error' => null,
            ])->save();

            return $imported;
        } catch (Throwable $e) {
            $state->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function dashboards(): array
    {
        $path = rtrim((string) config('telemetry.grafana.dashboards_path'), '/');
        $files = glob($path.'/*.json') ?: [];
        sort($files);

        $dashboards = [];

        foreach ($files as $file) {
            $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            if (is_array($json) && isset($json['uid'])) {
                $dashboards[] = $json;
            }
        }

        return $dashboards;
    }
}
