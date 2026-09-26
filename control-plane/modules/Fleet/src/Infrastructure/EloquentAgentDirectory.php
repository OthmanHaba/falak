<?php

namespace Kiln\Fleet\Infrastructure;

use DateTimeInterface;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Fleet\Contracts\AgentStatus;
use Kiln\Fleet\Contracts\Data\AgentInfo;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\AgentMetric;

final class EloquentAgentDirectory implements AgentDirectory
{
    public function forServer(string $serverId): ?AgentInfo
    {
        return $this->forServers([$serverId])[$serverId] ?? null;
    }

    public function forServers(array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }

        return Agent::query()
            ->whereIn('server_id', $serverIds)
            ->where('status', '!=', AgentStatus::Revoked)
            ->orderBy('enrolled_at')
            ->get()
            ->keyBy('server_id')
            ->map(fn (Agent $agent) => $agent->toInfo())
            ->all();
    }

    public function metrics(string $serverId, DateTimeInterface $since): array
    {
        return AgentMetric::query()
            ->where('server_id', $serverId)
            ->where('at', '>=', $since)
            ->orderBy('at')
            ->limit(2000)
            ->get()
            ->map(fn (AgentMetric $metric) => $metric->toSample())
            ->values()
            ->all();
    }
}
