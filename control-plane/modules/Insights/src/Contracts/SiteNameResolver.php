<?php

namespace Kiln\Insights\Contracts;

/**
 * Display names for sites (site ids are opaque ULIDs to Insights).
 *
 * Insights binds a default implementation that returns the id itself. The Sites module will
 * bind its own implementation of this contract once it exists; Insights never imports Sites.
 */
interface SiteNameResolver
{
    public function name(string $siteId): string;

    /**
     * @param  list<string>  $siteIds
     * @return array<string, string> keyed by site id
     */
    public function names(array $siteIds): array;
}
