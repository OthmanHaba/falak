<?php

namespace Falak\Limits\Infrastructure;

use Falak\Limits\Contracts\LimitDefaults;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;

/**
 * Defaults by the environment the site is placed in (Projects). A site in no project counts as production: it only
 * gets the limits it was given.
 */
final class EnvironmentLimitDefaults implements LimitDefaults
{
    public function __construct(private readonly ProjectDirectory $projects) {}

    public function forSite(string $siteId): ResourceLimits
    {
        $placed = $this->projects->projectOf(ServiceKind::Site, $siteId);
        $environment = $placed !== null ? $this->projects->environment($placed->environmentId) : null;
        $production = $environment === null || $environment->isProduction;

        return ResourceLimits::fromArray((array) config($production ? 'limits.defaults.production' : 'limits.defaults.non_production', []));
    }

    public function effective(ResourceLimits $own, string $siteId): ResourceLimits
    {
        return $own->withDefaults($this->forSite($siteId));
    }
}
