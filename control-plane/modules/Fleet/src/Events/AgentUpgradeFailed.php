<?php

namespace Falak\Fleet\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A server's agent could not be upgraded (download / checksum / pre-flight failed, or it did not come back with the
 * new build). The previous binary keeps running unless the agent never came back.
 */
final class AgentUpgradeFailed implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'fleet.agent_upgrade_failed';

    public function __construct(
        public string $upgradeId,
        public string $organizationId,
        public string $serverId,
        public ?string $hostname,
        public string $toVersion,
        public string $error,
    ) {}

    public static function dedupKey(string $serverId): string
    {
        return "fleet.agent_upgrade:{$serverId}";
    }

    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::ALERT_TYPE, Severity::Warning,
            'Agent upgrade to '.$this->toVersion.' failed'.($this->hostname ? " on {$this->hostname}" : ''), $this->error,
            "/servers/{$this->serverId}", self::dedupKey($this->serverId), context: ['server_id' => $this->serverId, 'upgrade_id' => $this->upgradeId]);
    }
}
