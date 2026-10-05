<?php

namespace Falak\Edge\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * edge.cert.install failed on a server (the domains keep their previous certificate there).
 */
final class CertificateInstallFailed implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'edge.certificate_failed';

    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        public string $certificateId,
        public string $organizationId,
        public string $serverId,
        public ?string $siteId,
        public array $domains,
        public string $error,
    ) {}

    public static function dedupKey(string $certificateId, string $serverId): string
    {
        return "edge.certificate:{$certificateId}:{$serverId}";
    }

    public function toAlert(): AlertData
    {
        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Critical,
            'Certificate for '.implode(', ', array_slice($this->domains, 0, 3)).(count($this->domains) > 3 ? '…' : '').' could not be installed',
            $this->error,
            $this->siteId ? "/sites/{$this->siteId}/domains" : null,
            self::dedupKey($this->certificateId, $this->serverId),
            context: array_filter(['server_id' => $this->serverId, 'site_id' => $this->siteId, 'certificate_id' => $this->certificateId]),
        );
    }
}
