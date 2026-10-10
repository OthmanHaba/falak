<?php

namespace Falak\Edge\Contracts;

use Falak\Edge\Contracts\Data\PreviewDomainData;
use Illuminate\Validation\ValidationException;

/**
 * The instance's preview domain and the routing of preview sites under it, for Previews.
 *
 * TLS: with Cloudflare, the preview edge server holds one `*.<domain>` certificate (ACME DNS-01) that serves every
 * preview host there; a preview on another server gets an A record for its host and its own certificate (HTTP-01).
 * Without a managed DNS provider the user keeps `*.<domain>` pointing at the edge server and each live preview host
 * gets an HTTP-01 certificate as it is routed: only hosts Falak routes ever get one (never on demand for any name).
 */
interface PreviewDomains
{
    public function settings(): ?PreviewDomainData;

    /**
     * Set the preview domain: $serverId and $dnsCredentialId must belong to $organizationId. With a Cloudflare
     * credential the `*.<domain>` record is created (or moved) to point at the server.
     *
     * @throws ValidationException
     */
    public function configure(string $organizationId, string $domain, ?string $dnsCredentialId, string $serverId, ?string $actorId = null): PreviewDomainData;

    /** Stop serving previews under the domain (the wildcard record Falak created is deleted). */
    public function clear(): void;

    /**
     * Route $host (under the preview domain) to a preview site (a compose site's public $service), with the DNS record
     * its server needs when it is not the edge server.
     *
     * @throws ValidationException the host is taken, not under the domain, or its server can't be reached by name
     */
    public function route(string $siteId, string $host, ?string $service = null): void;

    /** Delete the DNS records Falak made for the site's preview hosts (its domains go with the site). */
    public function release(string $siteId): void;

    /** Whether a name is free (no domain row, no preview record). */
    public function available(string $host): bool;

    /** Basic auth in front of every route of the site (the password is hashed by Edge). */
    public function protect(string $siteId, string $username, #[\SensitiveParameter] string $password): void;
}
