<?php

namespace Falak\Edge\Contracts\Data;

/**
 * The instance's preview domain (never carries the DNS token).
 */
final readonly class PreviewDomainData
{
    public function __construct(
        public string $domain,
        /** The operator's organization (its DNS credential and edge server) */
        public string $organizationId,
        /** The preview edge server: `*.<domain>` points at it */
        public string $serverId,
        public ?string $dnsCredentialId,
        /** Cloudflare: Falak keeps the wildcard record and the edge's DNS-01 wildcard certificate */
        public bool $managedDns,
        /** pending | active | manual | error */
        public string $status,
        public ?string $error,
    ) {}
}
