<?php

namespace Falak\Edge\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A certificate was installed on a server (custom upload via edge.cert.install).
 */
final class CertificateIssued implements Alertable
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

    public const ALERT_TYPE = 'edge.certificate_installed';

    /** Recovery of {@see CertificateInstallFailed}: only delivered after a failure alert for the certificate on this server. */
    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::ALERT_TYPE, Severity::Info, 'Certificate for '.implode(', ', array_slice($this->domains, 0, 3)).' installed', '', null,
            CertificateInstallFailed::dedupKey($this->certificateId, $this->serverId), resolves: true, context: ['server_id' => $this->serverId, 'certificate_id' => $this->certificateId]);
    }
}
