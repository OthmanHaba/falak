<?php

namespace Falak\Limits\Contracts;

/**
 * Limits a site's services get for the values their own leave unset, by the site's environment: none in production
 * (or for a site in no project), config('limits.defaults.non_production') elsewhere (staging, previews).
 */
interface LimitDefaults
{
    public function forSite(string $siteId): ResourceLimits;

    /** $own with the site environment's defaults filled in: what is enforced. */
    public function effective(ResourceLimits $own, string $siteId): ResourceLimits;
}
