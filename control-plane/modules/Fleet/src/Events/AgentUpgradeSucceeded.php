<?php

namespace Falak\Fleet\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A server's agent runs the build the control plane ships.
 */
final class AgentUpgradeSucceeded implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'fleet.agent_upgraded';

    public function __construct(
        public string $upgradeId,
        public string $organizationId,
        public string $serverId,
        public ?string $hostname,
        public ?string $fromVersion,
        public string $version,
    ) {}

    /** Recovery of {@see AgentUpgradeFailed}: only delivered after a failure alert for the server. */
    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::ALERT_TYPE, Severity::Info, 'Agent upgraded to '.$this->version, '',
            "/servers/{$this->serverId}", AgentUpgradeFailed::dedupKey($this->serverId), resolves: true, context: ['server_id' => $this->serverId]);
    }
}
