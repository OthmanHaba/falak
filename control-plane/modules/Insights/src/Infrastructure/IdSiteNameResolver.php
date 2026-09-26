<?php

namespace Kiln\Insights\Infrastructure;

use Kiln\Insights\Contracts\SiteNameResolver;

/**
 * Default until the Sites module binds its own SiteNameResolver: the id is the name.
 */
final class IdSiteNameResolver implements SiteNameResolver
{
    public function name(string $siteId): string
    {
        return $siteId;
    }

    public function names(array $siteIds): array
    {
        return array_combine($siteIds, $siteIds);
    }
}
