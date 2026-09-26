<?php

namespace Kiln\Sites\Contracts;

/**
 * Primary domain lookup for site listings and headers. Sites binds a null implementation;
 * Edge (which owns domains) rebinds it — Sites never depends on Edge directly.
 */
interface SiteDomains
{
    /**
     * @param  list<string>  $siteIds
     * @return array<string, string> primary domain keyed by site id (sites without one are omitted)
     */
    public function primaryDomains(array $siteIds): array;
}
