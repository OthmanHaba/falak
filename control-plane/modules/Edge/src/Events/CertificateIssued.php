<?php

namespace Kiln\Edge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A certificate was installed on a server (custom upload via edge.cert.install).
 */
final class CertificateIssued
{
    use Dispatchable;

    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        public string $certificateId,
        public string $organizationId,
        public string $serverId,
        public array $domains,
        public ?string $notAfter,
        public string $source = 'custom',
    ) {}
}
