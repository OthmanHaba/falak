<?php

namespace Falak\Fleet\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

final class AgentRevoked implements Alertable
{
    use Dispatchable;

    public function __construct(
        public string $agentId,
        public string $organizationId,
        public ?string $serverId,
        public string $reason,
    ) {}

    public const ALERT_TYPE = 'fleet.agent_revoked';

    public function toAlert(): AlertData
    {
        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Warning,
            'Server agent revoked',
            "Agent {$this->agentId} was revoked: {$this->reason}",
            $this->serverId ? "/servers/{$this->serverId}" : null,
            context: array_filter(['server_id' => $this->serverId, 'agent_id' => $this->agentId, 'reason' => $this->reason]),
        );
    }
}
