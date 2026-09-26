<?php

namespace Kiln\Sites\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SiteCreated
{
    use Dispatchable;

    /**
     * @param  list<string>  $serverIds
     */
    public function __construct(
        public string $siteId,
        public string $organizationId,
        public string $slug,
        public string $runtime,
        public array $serverIds,
    ) {}
}
