<?php

namespace Falak\Security\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A check of high or critical severity started failing on a server.
 */
final class CriticalFindingDetected implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'security.critical_finding';

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $serverName,
        public string $checkId,
        public string $title,
        public string $severity,
        public string $evidence,
    ) {}

    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::ALERT_TYPE, $this->severity === 'critical' ? Severity::Critical : Severity::Warning,
            "{$this->serverName}: {$this->title} — failing", $this->evidence, "/servers/{$this->serverId}/security",
            "security.finding:{$this->serverId}:{$this->checkId}", context: ['server_id' => $this->serverId, 'check' => $this->checkId, 'severity' => $this->severity]);
    }
}
