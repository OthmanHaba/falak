<?php

namespace Falak\Fleet\Contracts;

use DateTimeInterface;
use Falak\Fleet\Contracts\Data\AgentInfo;
use Falak\Fleet\Contracts\Data\MetricSample;

/**
 * Read-only view of agents for other modules.
 */
interface AgentDirectory
{
    /** The server's current (most recently enrolled, non-revoked) agent. */
    public function forServer(string $serverId): ?AgentInfo;

    /**
     * @param  list<string>  $serverIds
     * @return array<string, AgentInfo> keyed by server id
     */
    public function forServers(array $serverIds): array;

    /**
     * Heartbeat metric samples since $since (oldest first).
     *
     * @return list<MetricSample>
     */
    public function metrics(string $serverId, DateTimeInterface $since): array;
}
