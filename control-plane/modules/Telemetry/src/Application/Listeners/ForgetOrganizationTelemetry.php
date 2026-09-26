<?php

namespace Kiln\Telemetry\Application\Listeners;

use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Telemetry\Domain\Models\DeploymentAnnotation;
use Kiln\Telemetry\Domain\Models\GrafanaState;
use Kiln\Telemetry\Domain\Models\TelemetrySettings;

final class ForgetOrganizationTelemetry
{
    public function handle(OrganizationDeleted $event): void
    {
        TelemetrySettings::query()->whereKey($event->organizationId)->delete();
        GrafanaState::query()->whereKey($event->organizationId)->delete();
        DeploymentAnnotation::query()->where('organization_id', $event->organizationId)->delete();
    }
}
