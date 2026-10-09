<?php

namespace Falak\Limits\Contracts;

/**
 * Limits new services start with, by the site's environment: none in production (or for a site in no project),
 * config('limits.defaults.non_production') elsewhere (staging, previews). They are written on the service when it is
 * created (a site placed in an environment, a new worker or daemon) and never merged at runtime: services that existed
 * before, or whose environment changes, keep exactly the limits they have.
 */
interface LimitDefaults
{
    public function forSite(string $siteId): ResourceLimits;
}
