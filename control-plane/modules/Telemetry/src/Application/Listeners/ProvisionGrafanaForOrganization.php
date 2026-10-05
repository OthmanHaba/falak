<?php

namespace Falak\Telemetry\Application\Listeners;

use Falak\Identity\Events\OrganizationCreated;
use Falak\Telemetry\Application\Actions\ProvisionGrafana;
use Falak\Telemetry\Infrastructure\Grafana\GrafanaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProvisionGrafanaForOrganization implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        private readonly GrafanaClient $grafana,
        private readonly ProvisionGrafana $provision,
    ) {}

    public function handle(OrganizationCreated $event): void
    {
        if (! $this->grafana->configured()) {
            return;
        }

        try {
            ($this->provision)($event->organizationId);
        } catch (Throwable $e) {
            Log::warning('Grafana provisioning failed', ['organization_id' => $event->organizationId, 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
