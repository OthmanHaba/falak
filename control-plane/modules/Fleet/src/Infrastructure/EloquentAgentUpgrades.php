<?php

namespace Falak\Fleet\Infrastructure;

use Falak\Fleet\Application\AgentUpgradeRollout;
use Falak\Fleet\Application\ShippedAgent;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\AgentUpgrades;
use Falak\Fleet\Contracts\AgentUpgradeStatus;
use Falak\Fleet\Contracts\Data\AgentUpgradeData;
use Falak\Fleet\Contracts\Data\AgentVersionInfo;
use Falak\Fleet\Contracts\Exceptions\AgentUpgradeUnavailable;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\AgentUpgrade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentAgentUpgrades implements AgentUpgrades
{
    public function __construct(
        private readonly ShippedAgent $shipped,
        private readonly AgentUpgradeRollout $rollout,
    ) {}

    public function versionsFor(array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }

        $agents = $this->currentAgents(fn ($q) => $q->whereIn('server_id', $serverIds));
        $upgrades = AgentUpgrade::query()->whereIn('server_id', $serverIds)->orderBy('created_at')->orderBy('id')->get()->keyBy('server_id');
        $out = [];

        foreach ($agents as $agent) {
            $shipped = $this->shipped->for($agent->arch);
            $out[(string) $agent->server_id] = new AgentVersionInfo(
                (string) $agent->server_id,
                $agent->agent_version,
                $shipped['version'] ?? null,
                ShippedAgent::outdated($agent->agent_version, $this->sha($agent), $shipped),
                $upgrades->get($agent->server_id)?->toData(),
            );
        }

        return $out;
    }

    public function upgrade(string $serverId, ?string $userId = null): AgentUpgradeData
    {
        $agent = $this->currentAgents(fn ($q) => $q->where('server_id', $serverId))->first()
            ?? throw new AgentUpgradeUnavailable('This server has no agent.');

        if ($active = $this->active($agent)) {
            return $active->toData();
        }

        $upgrade = $this->queue($agent, null, $userId, explicit: true);
        $this->rollout->advance($agent->organization_id);

        return $upgrade->refresh()->toData();
    }

    public function upgradeOrganization(string $organizationId, ?string $userId = null, ?array $serverIds = null): array
    {
        $rolloutId = strtolower((string) Str::ulid());
        $queued = [];
        $scope = fn ($q) => $q->where('organization_id', $organizationId)->whereNotNull('server_id')
            ->when($serverIds !== null, fn ($q) => $q->whereIn('server_id', array_map('strtolower', $serverIds)));

        foreach ($this->currentAgents($scope) as $agent) {
            if ($agent->status !== AgentStatus::Online || $this->active($agent) !== null
                || ! ShippedAgent::outdated($agent->agent_version, $this->sha($agent), $this->shipped->for($agent->arch))) {
                continue;
            }

            $queued[] = $this->queue($agent, $rolloutId, $userId, explicit: false);
        }

        $this->rollout->advance($organizationId);

        return array_map(fn (AgentUpgrade $u) => $u->refresh()->toData(), $queued);
    }

    public function outdatedCount(?string $organizationId = null): int
    {
        return $this->currentAgents(fn ($q) => $q->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->whereNotNull('server_id'))
            ->filter(fn (Agent $agent) => ShippedAgent::outdated($agent->agent_version, $this->sha($agent), $this->shipped->for($agent->arch)))
            ->count();
    }

    private function queue(Agent $agent, ?string $rolloutId, ?string $userId, bool $explicit): AgentUpgrade
    {
        if ($agent->status !== AgentStatus::Online) {
            throw new AgentUpgradeUnavailable('The agent is offline; it can be upgraded once it is connected again.');
        }

        $shipped = $this->shipped->for($agent->arch)
            ?? throw new AgentUpgradeUnavailable('This control plane publishes no verifiable falak-agent build for '.($agent->arch ?? 'this architecture').' (see FALAK_AGENT_BINARIES_PATH / FALAK_AGENT_DOWNLOAD_URL + FALAK_AGENT_SHA256_*).');

        if ($explicit && $this->sha($agent) === $shipped['sha256']) {
            throw new AgentUpgradeUnavailable("The agent already runs {$shipped['version']}.");
        }

        return DB::transaction(fn () => AgentUpgrade::query()->create([
            'organization_id' => $agent->organization_id,
            'agent_id' => $agent->id,
            'server_id' => (string) $agent->server_id,
            'rollout_id' => $rolloutId,
            'status' => AgentUpgradeStatus::Queued,
            'arch' => (string) $agent->arch,
            'from_version' => $agent->agent_version,
            'to_version' => $shipped['version'],
            'sha256' => $shipped['sha256'],
            'requested_by' => $userId,
        ]));
    }

    private function active(Agent $agent): ?AgentUpgrade
    {
        return AgentUpgrade::query()->where('agent_id', $agent->id)
            ->whereIn('status', [AgentUpgradeStatus::Queued, AgentUpgradeStatus::Running])->latest()->first();
    }

    private function sha(Agent $agent): ?string
    {
        $sha = ($agent->facts ?? [])['agent_sha256'] ?? null;

        return is_string($sha) ? strtolower($sha) : null;
    }

    /**
     * The current (latest enrolled, non-revoked) agent of each matching server.
     *
     * @param  callable(Builder<Agent>): mixed  $scope
     * @return Collection<int, Agent>
     */
    private function currentAgents(callable $scope)
    {
        $query = Agent::query()->where('status', '!=', AgentStatus::Revoked)->orderBy('enrolled_at');
        $scope($query);

        return $query->get()->keyBy('server_id')->values();
    }
}
