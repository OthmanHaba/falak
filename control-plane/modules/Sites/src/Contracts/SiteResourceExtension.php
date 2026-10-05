<?php

namespace Falak\Sites\Contracts;

/**
 * Extra fields other modules contribute to the public site API resource (GET /api/v1/sites[/…]),
 * e.g. Deployments adds `strategy` and `current_release`. Implementations are tagged with
 * {@see self::TAG} in the container; Sites never depends on the contributing module.
 */
interface SiteResourceExtension
{
    public const TAG = 'sites.resource_extensions';

    /**
     * @param  list<string>  $siteIds
     * @return array<string, array<string, mixed>> fields keyed by site id
     */
    public function fields(array $siteIds): array;
}
