<?php

namespace Falak\Deployments\Infrastructure;

use Falak\Deployments\Contracts\DeploymentBadges;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\WatchStatus;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\ReleaseWatch;

final class EloquentDeploymentBadges implements DeploymentBadges
{
    public const WATCHING = 'Watching';

    public const ROLLED_BACK = 'Rolled back';

    public function forSites(array $siteIds): array
    {
        $siteIds = array_values(array_unique($siteIds));

        if ($siteIds === []) {
            return [];
        }

        $badges = [];

        foreach (ReleaseWatch::query()->whereIn('site_id', $siteIds)->where('status', WatchStatus::Watching)->distinct()->pluck('site_id') as $siteId) {
            $badges[$siteId][] = self::WATCHING;
        }

        // The newest successful deployment of each site: an automatic rollback means the site runs the release its
        // watch went back to.
        $newest = Deployment::query()->selectRaw('site_id as newest_site_id, max(number) as newest_number')
            ->whereIn('site_id', $siteIds)->where('status', DeploymentStatus::Succeeded)->groupBy('site_id');

        Deployment::query()
            ->joinSub($newest, 'newest', fn ($join) => $join
                ->on('deployments_deployments.site_id', '=', 'newest.newest_site_id')
                ->on('deployments_deployments.number', '=', 'newest.newest_number'))
            ->whereNotNull('deployments_deployments.auto_rollback_of')
            ->pluck('deployments_deployments.site_id')
            ->each(function (string $siteId) use (&$badges) {
                $badges[$siteId][] = self::ROLLED_BACK;
            });

        return $badges;
    }
}
