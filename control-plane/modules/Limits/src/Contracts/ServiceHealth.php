<?php

namespace Falak\Limits\Contracts;

/**
 * Recent OOM kills and restart loops, as badges for services' cards ("OOM killed", "Restarting").
 */
interface ServiceHealth
{
    /**
     * Badges of each site: its own (container, slice, compose services) and those of its workers and daemons.
     *
     * @param  list<string>  $siteIds
     * @return array<string, list<string>> by site id (sites without any are left out)
     */
    public function badgesForSites(array $siteIds): array;

    /**
     * Badges of database instances.
     *
     * @param  list<string>  $instanceIds
     * @return array<string, list<string>> by instance id
     */
    public function badgesForInstances(array $instanceIds): array;
}
