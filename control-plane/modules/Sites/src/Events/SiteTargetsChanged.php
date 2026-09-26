<?php

namespace Kiln\Sites\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SiteTargetsChanged
{
    use Dispatchable;

    /**
     * @param  list<string>  $added  server ids
     * @param  list<string>  $removed  server ids
     * @param  list<string>  $serverIds  current target server ids
     */
    public function __construct(
        public string $siteId,
        public string $organizationId,
        public array $added,
        public array $removed,
        public array $serverIds,
        public ?string $leaderServerId,
    ) {}
}
