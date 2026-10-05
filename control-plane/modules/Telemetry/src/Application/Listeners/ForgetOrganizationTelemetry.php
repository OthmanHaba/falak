<?php

namespace Falak\Telemetry\Application\Listeners;

use Falak\Identity\Events\OrganizationDeleted;
use Falak\Telemetry\Domain\Models\DeploymentAnnotation;
use Falak\Telemetry\Domain\Models\GrafanaState;
use Falak\Telemetry\Domain\Models\TelemetrySettings;

final class ForgetOrganizationTelemetry
{
    public function handle(OrganizationDeleted $event): void
    {
        TelemetrySettings::query()->whereKey($event->organizationId)->delete();
        GrafanaState::query()->whereKey($event->organizationId)->delete();
        DeploymentAnnotation::query()->where('organization_id', $event->organizationId)->delete();
    }
}
