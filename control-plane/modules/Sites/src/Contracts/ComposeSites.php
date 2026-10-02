<?php

namespace Kiln\Sites\Contracts;

use Kiln\Sites\Contracts\Data\ComposeServiceState;
use Kiln\Sites\Contracts\Data\ComposeVersionData;
use Kiln\Sites\Contracts\Data\RenderedCompose;
use Kiln\Sites\Contracts\Exceptions\ComposeRenderException;

/**
 * Compose sites for other modules: Deployments renders releases and reports service state, Projects shows
 * service health on the canvas.
 */
interface ComposeSites
{
    /** Inline compose file (latest or a given version); null for repo sources / unknown sites. */
    public function content(string $siteId, ?int $version = null): ?ComposeVersionData;

    /**
     * Edge owns the domains of public services (edge_domains rows per service); it mirrors each service's first
     * domain here so PublicService::$domain (and `public_services[].domain`) stays the read model. Services missing
     * from $domains are left alone; null clears the domain. Saves quietly (no SiteUpdated).
     *
     * @param  array<string, ?string>  $domains  service => first domain
     */
    public function setPublicDomains(string $siteId, array $domains): void;

    /**
     * The compose project to show for the site: the latest inline file, or for repository stacks the merged project
     * last read from git (at creation, a Settings → Compose save or a deploy). Null when none is known yet.
     */
    public function project(string $siteId): ?string;

    /**
     * For a site that runs a compose stack's service as its own Kiln site: the stack's Docker networks its container
     * joins on $serverId, under the service's name and the aliases it declared on each network, so the stack's
     * services and it keep resolving each other. Empty when the site isn't split out of a stack, the service was on no
     * stack network (`network_mode`), the stack doesn't run on that server, or the stack is gone.
     *
     * @return list<array{name: string, aliases: list<string>}>
     */
    public function stackNetworks(string $siteId, string $serverId): array;

    /** The organization's "Allow privileged compose" setting (off by default). */
    public function allowsPrivileged(string $organizationId): bool;

    /**
     * Render the compose file for a release: `build:` services replaced by $images (digest-pinned refs),
     * public services published on 127.0.0.1:<host port>, other host ports removed, kiln.site / kiln.release /
     * kiln.service labels, policy enforced.
     *
     * Kiln's adjustments (docs/plans/COMPOSE_APPS.md) apply first: services replaced by Kiln databases or sites are
     * removed and, for repository projects, mounted repository files point at the release's `repo/` copies.
     *
     * @param  string  $yaml  source compose file (inline content or the project returned by the build)
     * @param  array<string, string>  $images  service => image ref of built services
     * @param  ?list<string>  $repoFiles  repository files shipped with the release (null: inline, or a builder that
     *                                    doesn't merge projects — paths stay relative to the release directory)
     *
     * @throws ComposeRenderException
     */
    public function render(string $siteId, string $yaml, array $images, string $releaseId, ?array $repoFiles = null): RenderedCompose;

    /**
     * Pin `image:` of services to the digests the servers resolved (rollback is exact).
     *
     * @param  array<string, string>  $digests  service => sha256:…
     */
    public function pinDigests(string $yaml, array $digests): string;

    /**
     * Record what a server reported for the site's compose project (docker.compose.up / ps results).
     *
     * @param  list<array<string, mixed>>  $services  agent ServiceStatus objects
     */
    public function recordStatus(string $siteId, string $serverId, array $services): void;

    /**
     * Last reported service states on every server of the site.
     *
     * @return list<ComposeServiceState>
     */
    public function status(string $siteId): array;
}
