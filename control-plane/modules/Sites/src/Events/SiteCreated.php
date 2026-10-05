<?php

namespace Falak\Sites\Events;

use Falak\Sites\Contracts\Data\SitePlacement;
use Illuminate\Foundation\Events\Dispatchable;

final class SiteCreated
{
    use Dispatchable;

    /**
     * @param  list<string>  $serverIds
     * @param  ?SitePlacement  $placement  where the creator asked Projects to place the site
     */
    public function __construct(
        public string $siteId,
        public string $organizationId,
        public string $slug,
        public string $runtime,
        public array $serverIds,
        public ?SitePlacement $placement = null,
    ) {}
}
