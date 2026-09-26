<?php

namespace Kiln\Deployments\Application\Listeners;

use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\DeploymentStep;
use Kiln\Deployments\Domain\Models\DeploymentTarget;
use Kiln\Deployments\Domain\Models\OutputLine;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Deployments\Domain\Models\SiteSettings;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Sites\Events\SiteDeleted;

final class ForgetDeletedResources
{
    public function siteDeleted(SiteDeleted $event): void
    {
        Deployment::query()->where('site_id', $event->siteId)->whereIn('status', [DeploymentStatus::Queued, ...DeploymentStatus::active()])
            ->update(['status' => DeploymentStatus::Cancelled, 'error' => 'The site was deleted.', 'finished_at' => now(), 'updated_at' => now()]);
        SiteSettings::query()->whereKey($event->siteId)->delete();
    }

    public function organizationDeleted(OrganizationDeleted $event): void
    {
        $ids = Deployment::query()->where('organization_id', $event->organizationId)->pluck('id');

        foreach ($ids->chunk(500) as $chunk) {
            OutputLine::query()->whereIn('deployment_id', $chunk)->delete();
            DeploymentStep::query()->whereIn('deployment_id', $chunk)->delete();
            DeploymentTarget::query()->whereIn('deployment_id', $chunk)->delete();
        }

        Deployment::query()->where('organization_id', $event->organizationId)->delete();
        Release::query()->where('organization_id', $event->organizationId)->delete();
        SiteSettings::query()->where('organization_id', $event->organizationId)->delete();
    }
}
