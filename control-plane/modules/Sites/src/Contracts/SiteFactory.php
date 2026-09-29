<?php

namespace Kiln\Sites\Contracts;

use Illuminate\Validation\ValidationException;
use Kiln\Sites\Contracts\Data\CreatedSite;
use Kiln\Sites\Contracts\Data\SitePlacement;

/**
 * Site creation for other modules (Projects' canvas and environment duplication). Same rules, side effects
 * (targets, deploy key, audit, SiteCreated) and validation as the Sites create form and API.
 */
interface SiteFactory
{
    /**
     * @param  array<string, mixed>  $data  the fields of POST /api/v1/sites (name, framework, runtime, server_ids, …). Compose
     *                                      sites (docs/COMPOSE_TEMPLATES.md §5): runtime `compose` (framework optional), `compose_source`
     *                                      inline|repo, `compose_content` (inline), `compose_file` (repo), `public_services`
     *                                      [{service, port, domain?}], `variables` {KEY: value} (initial environment, may contain
     *                                      ${{ service.KEY }} references) and `template` {slug, version, source: catalog|custom}.
     *
     * @throws ValidationException
     */
    public function create(string $organizationId, ?string $userId, array $data, ?SitePlacement $placement = null): CreatedSite;

    /**
     * Copy a site's configuration, deploy script, Laravel toggles, shared paths and variables into a new
     * site. Servers are not copied (the copy has none unless `server_ids` is overridden) and push-to-deploy
     * is off unless overridden.
     *
     * @param  array{name?: string, name_suffix?: string, branch?: string, server_ids?: list<string>, leader_server_id?: string, push_to_deploy?: bool}  $overrides
     *
     * @throws ValidationException
     */
    public function duplicate(string $siteId, array $overrides = [], ?SitePlacement $placement = null, ?string $userId = null): CreatedSite;

    /**
     * Delete a site like DELETE /sites/{id} does (removes it from its servers, SiteDeleted). Unknown ids are ignored.
     * $deleteVolumes also removes a compose site's named volumes.
     */
    public function delete(string $siteId, bool $deleteVolumes = false): void;
}
