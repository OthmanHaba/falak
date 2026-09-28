<?php

namespace Kiln\Network\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kiln\Alerting\Contracts\Alertable;
use Kiln\Alerting\Contracts\Data\AlertData;
use Kiln\Alerting\Contracts\Severity;

/**
 * A server's firewall applies again after failing (dispatched on that transition only).
 */
final class FirewallApplied implements Alertable
{
    use Dispatchable;

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $commandId,
        public ?string $rulesetSha256,
    ) {}

    public const ALERT_TYPE = 'network.firewall_recovered';

    /** Recovery of {@see FirewallApplyFailed}: only delivered after a failure alert for the server. */
    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::ALERT_TYPE, Severity::Info, 'Firewall applied again', '',
            "/servers/{$this->serverId}/firewall", FirewallApplyFailed::dedupKey($this->serverId), resolves: true, context: ['server_id' => $this->serverId]);
    }
}
