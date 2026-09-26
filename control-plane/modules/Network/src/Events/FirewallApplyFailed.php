<?php

namespace Kiln\Network\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kiln\Alerting\Contracts\Alertable;
use Kiln\Alerting\Contracts\Data\AlertData;
use Kiln\Alerting\Contracts\Severity;

/**
 * The agent could not apply a server's nftables ruleset (the previous ruleset stays active).
 */
final class FirewallApplyFailed implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'network.firewall_failed';

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $commandId,
        public string $error,
    ) {}

    public static function dedupKey(string $serverId): string
    {
        return "network.firewall:{$serverId}";
    }

    public function toAlert(): AlertData
    {
        return new AlertData($this->organizationId, self::ALERT_TYPE, Severity::Critical, 'Firewall could not be applied', $this->error,
            "/network/servers/{$this->serverId}/firewall", self::dedupKey($this->serverId), context: ['server_id' => $this->serverId]);
    }
}
