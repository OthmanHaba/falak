<?php

namespace Falak\Sites\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SiteDeleted
{
    use Dispatchable;

    /**
     * @param  list<string>  $serverIds  servers the site was deployed to (the site no longer exists)
     */
    public function __construct(
        public string $siteId,
        public string $organizationId,
        public string $slug,
        public array $serverIds,
    ) {}
}
