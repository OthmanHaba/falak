<?php

namespace Falak\Fleet\Infrastructure;

use DateTimeInterface;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\Data\AgentInfo;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\AgentMetric;

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
