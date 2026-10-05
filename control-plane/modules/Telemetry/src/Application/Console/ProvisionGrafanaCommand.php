<?php

namespace Falak\Telemetry\Application\Console;

use Illuminate\Console\Command;
use Falak\Telemetry\Application\Actions\ProvisionGrafana;
use Falak\Telemetry\Domain\Models\GrafanaState;
use Falak\Telemetry\Infrastructure\Grafana\GrafanaClient;
use Throwable;

/**
 * Re-provision Grafana for one organization, or for every organization provisioned before
 * (telemetry_grafana_states), e.g. after upgrading the dashboards.
 */
final class ProvisionGrafanaCommand extends Command
{
    protected $signature = 'telemetry:grafana:provision {--organization=* : Organization id(s); defaults to every previously provisioned organization}';

    protected $description = 'Provision Grafana datasources, organization folders and Falak dashboards';

    public function handle(GrafanaClient $grafana, ProvisionGrafana $provision): int
    {
        if (! $grafana->configured()) {
            $this->error('Grafana is not configured (FALAK_GRAFANA_URL / FALAK_GRAFANA_TOKEN).');

            return self::FAILURE;
        }

        /** @var list<string> $organizations */
        $organizations = $this->option('organization') ?: GrafanaState::query()->pluck('organization_id')->all();

        if ($organizations === []) {
            $this->warn('No organizations to provision. Pass --organization=<id>.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($organizations as $organizationId) {
            try {
                $dashboards = $provision($organizationId);
                $this->info("{$organizationId}: ".count($dashboards).' dashboards');
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$organizationId}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
