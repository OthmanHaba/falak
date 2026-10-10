<?php

namespace Falak\Edge\Contracts;

/**
 * Which of the sites' domains have their DNS records kept by Falak (a Cloudflare zone of the organization): those
 * follow the site to new servers on their own; the others point wherever their owner set them (the Recovery wizard
 * lists them with the new server's addresses).
 */
interface DomainRecords
{
    /**
     * @param  list<string>  $siteIds
     * @return list<array{site_id: string, name: string, managed: bool, status: ?string}> status: the managed records'
     *                                                                                    worst state (pending | synced | conflict | error)
     */
    public function forSites(array $siteIds): array;
}
