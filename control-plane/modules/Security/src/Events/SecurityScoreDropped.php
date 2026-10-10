<?php

namespace Falak\Security\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A server's baseline score fell by 10 points or more since its previous audit.
 */
final class SecurityScoreDropped implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'security.score_dropped';

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $serverName,
        public int $from,
        public int $to,
    ) {}

    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::ALERT_TYPE, Severity::Warning, "Security score of {$this->serverName} dropped to {$this->to}",
            "The baseline score went from {$this->from} to {$this->to}.", "/servers/{$this->serverId}/security",
            context: ['server_id' => $this->serverId, 'from' => $this->from, 'to' => $this->to]);
    }
}
