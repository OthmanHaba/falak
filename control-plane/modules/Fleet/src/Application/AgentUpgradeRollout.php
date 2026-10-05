<?php

namespace Falak\Fleet\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Falak\Fleet\Application\Actions\QueueCommand;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\AgentUpgradeStatus;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Fleet\Contracts\Exceptions\InvalidCommandPayload;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\AgentUpgrade;
use Falak\Fleet\Events\AgentUpgradeFailed;
use Falak\Fleet\Events\AgentUpgradeSucceeded;

/**
 * Drives agent upgrades: queued → running (system.upgrade_agent sent) → installed (the agent swapped its binary and
 * restarts) → succeeded once its facts report the shipped build; failed on a command failure or when it does not come
 * back within `fleet.agent.upgrade.timeout_seconds`. Rollouts run `fleet.agent.upgrade.batch_size` upgrades at a
 * time and cancel what is left after a failure.
 */
final class AgentUpgradeRollout
{
    public const COMMAND = 'system.upgrade_agent';

    public function __construct(private readonly QueueCommand $queue) {}

    /** Start queued upgrades while the organization's rollouts have capacity (single upgrades start at once). */
    public function advance(string $organizationId): void
    {
        $batch = (int) config('fleet.agent.upgrade.batch_size', 2);

        $starts = DB::transaction(function () use ($organizationId, $batch) {
            $queued = AgentUpgrade::query()->where('organization_id', $organizationId)->where('status', AgentUpgradeStatus::Queued)
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $running = AgentUpgrade::query()->where('organization_id', $organizationId)->where('status', AgentUpgradeStatus::Running)
                ->whereNotNull('rollout_id')->count();
            $starts = [];

            foreach ($queued as $upgrade) {
                if ($upgrade->rollout_id !== null) {
                    if ($running >= $batch) {
                        continue;
                    }
                    $running++;
                }

                $upgrade->forceFill(['status' => AgentUpgradeStatus::Running, 'started_at' => now()])->save();
                $starts[] = $upgrade;
            }

            return $starts;
        });

        foreach ($starts as $upgrade) {
            $this->send($upgrade);
        }
    }

    private function send(AgentUpgrade $upgrade): void
    {
        $agent = Agent::query()->find($upgrade->agent_id);

        if ($agent === null || $agent->status !== AgentStatus::Online) {
            $this->fail($upgrade, 'The agent is not connected.');

            return;
        }

        try {
            $command = ($this->queue)($upgrade->server_id, self::COMMAND, [
                'version' => $upgrade->to_version,
                'url' => app(ShippedAgent::class)->for($upgrade->arch)['url'] ?? '',
                'sha256' => $upgrade->sha256,
            ], 600, "agent.upgrade:{$upgrade->id}");
        } catch (AgentUnavailable|InvalidCommandPayload $e) {
            $this->fail($upgrade, $e->getMessage());

            return;
        }

        $upgrade->forceFill(['command_id' => $command->id])->save();
    }

    /**
     * The command finished: the binary is in place and the agent restarts (or it already ran that build).
     *
     * @param  array<string, mixed>|null  $result
     */
    public function installed(string $commandId, ?array $result): void
    {
        $upgrade = AgentUpgrade::query()->where('command_id', $commandId)->where('status', AgentUpgradeStatus::Running)->first();

        if ($upgrade === null) {
            return;
        }

        if (($result['changed'] ?? true) === false) {
            $this->succeed($upgrade, is_string($result['version'] ?? null) ? $result['version'] : $upgrade->to_version);

            return;
        }

        $upgrade->forceFill(['installed' => true])->save();

        // The restarted agent may have reported its facts before this event was processed.
        $agent = Agent::query()->find($upgrade->agent_id);

        if ($agent !== null) {
            $this->reported($agent);
        }
    }

    /** The agent reported facts: done when they show the shipped build. */
    public function reported(Agent $agent): void
    {
        $upgrade = AgentUpgrade::query()->where('agent_id', $agent->id)->where('status', AgentUpgradeStatus::Running)->where('installed', true)->latest()->first();

        if ($upgrade === null) {
            return;
        }

        $facts = $agent->facts ?? [];
        $sha = is_string($facts['agent_sha256'] ?? null) ? strtolower($facts['agent_sha256']) : null;
        $matches = $sha !== null ? $sha === $upgrade->sha256 : $agent->agent_version === $upgrade->to_version;

        if ($matches) {
            $this->succeed($upgrade, (string) ($agent->agent_version ?? $upgrade->to_version));
        }
    }

    public function commandFailed(string $commandId, string $error): void
    {
        $upgrade = AgentUpgrade::query()->where('command_id', $commandId)->where('status', AgentUpgradeStatus::Running)->first();

        if ($upgrade !== null) {
            $this->fail($upgrade, $error);
        }
    }

    /** Fail upgrades whose agent did not come back with the new build in time. */
    public function sweep(): void
    {
        $timeout = (int) config('fleet.agent.upgrade.timeout_seconds', 600);

        AgentUpgrade::query()->where('status', AgentUpgradeStatus::Running)->where('started_at', '<', now()->subSeconds($timeout))
            ->each(fn (AgentUpgrade $upgrade) => $this->fail($upgrade, $upgrade->installed
                ? "The agent did not come back with {$upgrade->to_version} within ".intdiv($timeout, 60).' minutes (check `systemctl status falak-agent`; the previous binary is kept as /usr/local/bin/falak-agent.prev).'
                : 'The agent did not answer the upgrade command within '.intdiv($timeout, 60).' minutes.'));
    }

    private function succeed(AgentUpgrade $upgrade, string $version): void
    {
        if (! $this->transition($upgrade, AgentUpgradeStatus::Succeeded, null)) {
            return;
        }

        AgentUpgradeSucceeded::dispatch($upgrade->id, $upgrade->organization_id, $upgrade->server_id, $this->hostname($upgrade), $upgrade->from_version, $version);
        $this->advance($upgrade->organization_id);
    }

    private function fail(AgentUpgrade $upgrade, string $error): void
    {
        $error = Str::limit($error, 2000);

        if (! $this->transition($upgrade, AgentUpgradeStatus::Failed, $error)) {
            return;
        }

        if ($upgrade->rollout_id !== null) {
            AgentUpgrade::query()->where('rollout_id', $upgrade->rollout_id)->where('status', AgentUpgradeStatus::Queued)->update([
                'status' => AgentUpgradeStatus::Cancelled,
                'error' => 'Rollout stopped: the upgrade of '.($this->hostname($upgrade) ?? $upgrade->server_id).' failed.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        }

        AgentUpgradeFailed::dispatch($upgrade->id, $upgrade->organization_id, $upgrade->server_id, $this->hostname($upgrade), $upgrade->to_version, $error);
        $this->advance($upgrade->organization_id);
    }

    /** Conditional update: exactly one caller wins a terminal transition. */
    private function transition(AgentUpgrade $upgrade, AgentUpgradeStatus $status, ?string $error): bool
    {
        $updated = AgentUpgrade::query()->whereKey($upgrade->id)->where('status', AgentUpgradeStatus::Running)
            ->update(['status' => $status, 'error' => $error, 'finished_at' => now(), 'updated_at' => now()]);

        if ($updated === 1) {
            $upgrade->forceFill(['status' => $status, 'error' => $error])->syncOriginal();
        }

        return $updated === 1;
    }

    private function hostname(AgentUpgrade $upgrade): ?string
    {
        return Agent::query()->whereKey($upgrade->agent_id)->value('hostname');
    }
}
