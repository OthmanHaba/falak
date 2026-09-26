<?php

namespace Kiln\Deployments\Infrastructure;

use Kiln\Deployments\Domain\Enums\ReleaseStatus;
use Kiln\Deployments\Domain\Enums\Strategy;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Deployments\Domain\Models\SiteSettings;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteResourceExtension;

/**
 * Adds `strategy` and `current_release` to the public site API resource.
 */
final class DeploymentSiteFields implements SiteResourceExtension
{
    public function __construct(private readonly SiteDirectory $sites) {}

    public function fields(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $settings = SiteSettings::query()->whereIn('site_id', $siteIds)->get()->keyBy('site_id');
        $current = Release::query()->whereIn('site_id', $siteIds)->where('status', ReleaseStatus::Active)->get()->keyBy('site_id');
        $fields = [];

        foreach ($siteIds as $siteId) {
            $strategy = $settings->get($siteId)?->strategy;

            if ($strategy === null) {
                $site = $this->sites->find($siteId);
                $strategy = $site ? Strategy::default($site->runtime) : null;
            }

            $fields[$siteId] = [
                'strategy' => $strategy?->value,
                'current_release' => $current->get($siteId)?->toResource(),
            ];
        }

        return $fields;
    }
}
