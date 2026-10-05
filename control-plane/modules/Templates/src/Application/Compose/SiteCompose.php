<?php

namespace Falak\Templates\Application\Compose;

use Falak\Sites\Contracts\Data\SiteData;

/**
 * Reads the compose fields of a site (docs/COMPOSE_TEMPLATES.md §5) for "Save as template".
 */
interface SiteCompose
{
    /** The stored compose file of an inline compose site; null for repo sources / non-compose sites. */
    public function content(SiteData $site): ?string;

    /**
     * @return list<array{service: string, port: int, domain: ?string}>
     */
    public function publicServices(SiteData $site): array;
}
