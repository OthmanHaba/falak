<?php

namespace Kiln\Sites\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SiteUpdated
{
    use Dispatchable;

    /**
     * @param  list<string>  $changed  attribute names that changed (e.g. runtime, php_version, web_directory, laravel)
     * @param  list<string>  $serverIds
     */
    public function __construct(
        public string $siteId,
        public string $organizationId,
        public array $changed,
        public array $serverIds,
    ) {}

    public function changed(string ...$attributes): bool
    {
        return array_intersect($attributes, $this->changed) !== [];
    }
}
