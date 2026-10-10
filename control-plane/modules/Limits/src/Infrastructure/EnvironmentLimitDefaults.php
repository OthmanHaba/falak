<?php

namespace Falak\Limits\Infrastructure;

use Falak\Limits\Contracts\LimitDefaults;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;

/**
 * Defaults by the environment the site is placed in (Projects). A site in no project counts as production: it only
 * gets the limits it was given. Previews get smaller ones, a fork's pull request the smallest.
 */
final class EnvironmentLimitDefaults implements LimitDefaults
{
    public function __construct(private readonly ProjectDirectory $projects) {}

    public function forSite(string $siteId): ResourceLimits
    {
        $placed = $this->projects->projectOf(ServiceKind::Site, $siteId);
        $environment = $placed !== null ? $this->projects->environment($placed->environmentId) : null;
        $defaults = match (true) {
            $environment === null || $environment->isProduction => 'production',
            $environment->isForkPreview => 'fork_preview',
            $environment->isPreview => 'preview',
            default => 'non_production',
        };

        return ResourceLimits::fromArray((array) config("limits.defaults.{$defaults}", []));
    }
}
