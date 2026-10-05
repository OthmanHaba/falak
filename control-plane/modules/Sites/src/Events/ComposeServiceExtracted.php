<?php

namespace Falak\Sites\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A compose stack service now runs as a Falak service (a database or a site; $refId is the new database / site). The
 * stack no longer lists the service among its public services. Projects places a database next to the stack in its
 * environment (sites carry their placement in SiteCreated); Edge moves a split-out public service's domains
 * (edge_domains with compose_service = $service) to the new site.
 */
final class ComposeServiceExtracted
{
    use Dispatchable;

    /**
     * @param  'database'|'site'  $kind
     * @param  bool  $wasPrimary  the service was the stack's first public service (its domains were the site's own)
     */
    public function __construct(
        public string $siteId,
        public string $organizationId,
        public string $service,
        public string $kind,
        public string $refId,
        public string $name,
        public bool $wasPrimary = false,
    ) {}
}
