<?php

namespace Kiln\Sites\Infrastructure;

use Kiln\Sites\Contracts\SiteDomains;

/**
 * Used until a domains owner (Edge) rebinds SiteDomains.
 */
final class NullSiteDomains implements SiteDomains
{
    public function primaryDomains(array $siteIds): array
    {
        return [];
    }
}
