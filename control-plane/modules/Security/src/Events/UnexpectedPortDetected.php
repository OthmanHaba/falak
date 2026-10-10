<?php

namespace Falak\Security\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A port started listening on a public interface (or a container started publishing one around the firewall)
 * without Falak expecting it.
 */
final class UnexpectedPortDetected implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'security.unexpected_port';

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $serverName,
        public string $checkId,
        public string $title,
        public string $evidence,
    ) {}

    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::ALERT_TYPE, Severity::Warning, "{$this->serverName}: {$this->title}", $this->evidence,
            "/servers/{$this->serverId}/security", "security.port:{$this->serverId}:{$this->checkId}", context: ['server_id' => $this->serverId, 'check' => $this->checkId]);
    }
}
