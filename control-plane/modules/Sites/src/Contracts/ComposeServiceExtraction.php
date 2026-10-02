<?php

namespace Kiln\Sites\Contracts;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Contracts\Data\DatabaseData;
use Kiln\Sites\Contracts\Data\SiteData;

/**
 * Taking a service out of a compose stack (docs/plans/COMPOSE_APPS.md, lane contract): replace it with a
 * Kiln-managed database, or run it as its own Kiln site. Rendering removes extracted services and points the
 * stack's variables at their replacement ({@see rewrites()}).
 */
interface ComposeServiceExtraction
{
    /**
     * Create (or link $databaseId) a Kiln database on the site's leader for the service, record
     * `compose_services[service] = {mode: database, database_id}` and the variable rewrites. $compose is the merged
     * compose project (YAML) when known.
     *
     * @throws ValidationException
     */
    public function toDatabase(string $siteId, string $service, ?string $databaseId, string $engine, ?string $compose = null): DatabaseData;

    /**
     * Create a Kiln site for the service (framework/runtime the user picked, root directory = the service's build
     * context, same repository and branch, the service's environment as variables) and record
     * `{mode: site, site_id}`.
     *
     * @param  array<string, mixed>  $site  framework, runtime, name, …
     * @param  ?string  $compose  the merged compose project (YAML) when known, to read the service's environment
     *
     * @throws ValidationException
     */
    public function toSite(string $siteId, string $service, array $site, ?string $compose = null): SiteData;

    /**
     * Variable name → replacement (`${{ db.DATABASE_URL }}`, the internal URL of a split-out site) for the stack's
     * variables that pointed at extracted services. Rendering sets those environment entries to `${NAME}`; the
     * values themselves are site variables.
     *
     * @return array<string, string>
     */
    public function rewrites(string $siteId): array;
}
