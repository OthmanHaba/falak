<?php

namespace Kiln\Sites\Contracts;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Contracts\Data\DatabaseData;
use Kiln\Sites\Contracts\Data\ComposeRewrites;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;

/**
 * Takes a service out of a compose stack and runs it as a Kiln service instead (docs/plans/COMPOSE_APPS.md, phase 3):
 * a database engine service becomes a Kiln-managed database, an app service its own Kiln site. The decision is recorded
 * in the stack's `compose_services`; rendering drops the service and applies {@see rewrites()} to the stack's
 * variables so the rest of the stack points at the Kiln service.
 *
 * Callers check the actor's permissions (database creation for a database, site creation for a site). The service is
 * claimed under the stack's row lock before anything is created, so concurrent requests can't extract it twice, and a
 * failed creation gives it back.
 *
 * Every method takes the compose file the decision is made on ($compose: the merged YAML of the stack's files, as the
 * create flow / Settings → Compose read it, with relative paths rebased to the stack's root directory). When null,
 * inline stacks use their stored file and repository stacks the merged project read from the repository. Reading
 * the repository can also throw SourceControlException (NoApi for plain git servers, provider errors).
 */
interface ComposeServiceExtraction
{
    /**
     * Replace $service with a Kiln database on the stack's leader server: a new database (named after the service's
     * POSTGRES_DB / MYSQL_DATABASE / MARIADB_DATABASE, else <slug>_<service>) with its own user, or the existing
     * $databaseId of the same engine. Placed next to the stack in its environment.
     *
     * Redis / Valkey (official `redis` / `valkey/valkey` images only): a new instance <slug>-<service> on the leader,
     * which must run that engine (else a ValidationException saying why: the other cache engine, not installed, or not
     * offered for its OS), with the service's `--maxmemory` / `--maxmemory-policy` / `--appendonly yes` flags; the
     * stack's containers reach it through the Docker bridge (Databases' Redis network access). The container's data is
     * not copied.
     *
     * @param  string  $engine  postgresql | mysql | mariadb (the leader's engine) | redis | valkey (the image's)
     *
     * @throws ValidationException keys: service, engine, database_id, compose
     * @throws SourceControlException
     */
    public function toDatabase(string $siteId, string $service, ?string $databaseId, string $engine, ?string $compose = null): DatabaseData;

    /**
     * Run $service as its own Kiln site, created in the stack's environment from the same repository and branch:
     * `root_directory` = the service's build context (relative to the stack's root directory in the merged project), its `environment:` as the site's
     * variables, the stack's servers. $site are SiteFactory fields chosen by the user (framework, runtime, name,
     * domain, server_ids, …; they win over the derived ones).
     *
     * @param  array<string, mixed>  $site
     *
     * @throws ValidationException keys: service, compose, or SiteFactory's
     * @throws SourceControlException
     */
    public function toSite(string $siteId, string $service, array $site, ?string $compose = null): SiteData;

    /**
     * Stack variables pointing at extracted services, and what they become: a database's reference
     * (`${{ shop-db.DATABASE_URL }}`, resolved like any other reference) or a split-out site's URL — per remaining
     * service (`environment:`) and for the stack's own variables, so the same name in two services can point at
     * different databases.
     */
    public function rewrites(string $siteId): ComposeRewrites;
}
