<?php

namespace Falak\Sites\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new environment version was saved (values are not included; read them via SiteDirectory::environment()).
 */
final class SiteEnvironmentChanged
{
    use Dispatchable;

    /**
     * @param  list<string>  $changedKeys  added, removed or modified keys
     */
    public function __construct(
        public string $siteId,
        public string $organizationId,
        public int $version,
        public array $changedKeys,
    ) {}
}
