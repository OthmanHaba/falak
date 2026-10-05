<?php

namespace Falak\Deployments\Application\Listeners;

use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\DeploymentStep;
use Falak\Deployments\Domain\Models\DeploymentTarget;
use Falak\Deployments\Domain\Models\OutputLine;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\ServerRelease;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Deployments\Domain\Models\StepCommand;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Sites\Events\SiteDeleted;

final class ForgetDeletedResources
{
    public function siteDeleted(SiteDeleted $event): void
    {
        Deployment::query()->where('site_id', $event->siteId)->whereIn('status', [DeploymentStatus::Queued, ...DeploymentStatus::occupying()])
            ->update(['status' => DeploymentStatus::Cancelled, 'error' => 'The site was deleted.', 'finished_at' => now(), 'updated_at' => now()]);
        SiteSettings::query()->whereKey($event->siteId)->delete();
        ServerRelease::query()->where('site_id', $event->siteId)->delete();
    }

    public function organizationDeleted(OrganizationDeleted $event): void
    {
        $ids = Deployment::query()->where('organization_id', $event->organizationId)->pluck('id');

        foreach (Release::query()->where('organization_id', $event->organizationId)->distinct()->pluck('site_id')->chunk(500) as $sites) {
            ServerRelease::query()->whereIn('site_id', $sites)->delete();
        }

        foreach ($ids->chunk(500) as $chunk) {
            OutputLine::query()->whereIn('deployment_id', $chunk)->delete();
            DeploymentStep::query()->whereIn('deployment_id', $chunk)->delete();
            StepCommand::query()->whereIn('deployment_id', $chunk)->delete();
            DeploymentTarget::query()->whereIn('deployment_id', $chunk)->delete();
        }

        Deployment::query()->where('organization_id', $event->organizationId)->delete();
        Release::query()->where('organization_id', $event->organizationId)->delete();
        SiteSettings::query()->where('organization_id', $event->organizationId)->delete();
    }
}
