<?php

namespace Falak\Fleet\Contracts;

use Falak\Fleet\Contracts\Data\AgentUpgradeData;
use Falak\Fleet\Contracts\Data\AgentVersionInfo;
use Falak\Fleet\Contracts\Exceptions\AgentUpgradeUnavailable;
use Falak\Fleet\Events\AgentUpgradeFailed;
use Falak\Fleet\Events\AgentUpgradeSucceeded;

/**
 * Fleet agent upgrades: which servers run an older agent than the control plane ships, and `system.upgrade_agent`
 * rollouts (download from the panel + sha256, verified and swapped by the agent, which then restarts and reports
 * the new build in its facts). Outcomes: {@see AgentUpgradeSucceeded},
 * {@see AgentUpgradeFailed} (alerts).
 */
interface AgentUpgrades
{
    /**
     * @param  list<string>  $serverIds
     * @return array<string, AgentVersionInfo> keyed by server id (servers with an agent only)
     */
    public function versionsFor(array $serverIds): array;

    /**
     * Upgrade one server's agent now (returns the running upgrade when one is already in progress).
     *
     * @throws AgentUpgradeUnavailable
     */
    public function upgrade(string $serverId, ?string $userId = null): AgentUpgradeData;

    /**
     * Rolling upgrade of every online, outdated agent of the organization, `fleet.agent.upgrade.batch_size` at a
     * time; the rollout stops at the first failure. $serverIds limits it to those servers (the Servers list selection).
     *
     * @param  ?list<string>  $serverIds
     * @return list<AgentUpgradeData> the queued upgrades (empty when every agent is current)
     */
    public function upgradeOrganization(string $organizationId, ?string $userId = null, ?array $serverIds = null): array;

    /** Number of (non-revoked) agents running an older build than the one shipped; all organizations when null. */
    public function outdatedCount(?string $organizationId = null): int;
}
