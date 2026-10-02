<?php

namespace Kiln\Sites\Contracts;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Contracts\Data\DatabaseData;
use Kiln\Sites\Contracts\Data\SiteData;

/**
 * Takes a service out of a compose stack and runs it as a Kiln service instead (docs/plans/COMPOSE_APPS.md, phase 3):
 * a database engine service becomes a Kiln-managed database, an app service its own Kiln site. The decision is recorded
 * in the stack's `compose_services`; rendering drops the service and applies {@see rewrites()} to the stack's
 * variables so the rest of the stack points at the Kiln service.
 *
 * Every method takes the compose file the decision is made on ($compose: the merged YAML of the stack's files, as the
 * create flow / Settings → Compose read it). When null, inline stacks use their stored file and repository stacks the
 * file read from the repository.
 */
interface ComposeServiceExtraction
{
    /**
     * Replace $service with a Kiln database on the stack's leader server: a new database (named after the service's
     * POSTGRES_DB / MYSQL_DATABASE / MARIADB_DATABASE, else <slug>_<service>) with its own user, or the existing
     * $databaseId of the same engine. Placed next to the stack in its environment.
     *
     * @param  string  $engine  postgresql | mysql | mariadb (the leader's engine)
     *
     * @throws ValidationException keys: service, engine, database_id, compose
     */
    public function toDatabase(string $siteId, string $service, ?string $databaseId, string $engine, ?string $compose = null): DatabaseData;

    /**
     * Run $service as its own Kiln site, created in the stack's environment from the same repository and branch:
     * `root_directory` = the service's build context (relative to the compose file), its `environment:` as the site's
     * variables, the stack's servers. $site are SiteFactory fields chosen by the user (framework, runtime, name,
     * domain, server_ids, …; they win over the derived ones).
     *
     * @param  array<string, mixed>  $site
     *
     * @throws ValidationException keys: service, compose, or SiteFactory's
     */
    public function toSite(string $siteId, string $service, array $site, ?string $compose = null): SiteData;

    /**
     * Stack variables pointing at extracted services, and what they become: a database's reference
     * (`${{ shop-db.DATABASE_URL }}`, resolved like any other reference) or a split-out site's URL. Applied to the
     * variables of the remaining services (`environment:`) and to the stack's own variables.
     *
     * @return array<string, string> variable name => replacement
     */
    public function rewrites(string $siteId): array;
}
