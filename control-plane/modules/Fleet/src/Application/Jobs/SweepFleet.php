<?php

namespace Falak\Fleet\Application\Jobs;

use Falak\Fleet\Application\AgentUpgradeRollout;
use Falak\Fleet\Application\CommandLifecycle;
use Falak\Fleet\Application\CommandRedelivery;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\AgentMetric;
use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Domain\Models\InstallToken;
use Falak\Fleet\Events\AgentWentOffline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled every minute: offline detection, lost-command redelivery (lease expiry, see CommandRedelivery),
 * timeouts / expiry, agent upgrade timeouts, and pruning.
 */
final class SweepFleet implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 120;

    public function handle(CommandLifecycle $lifecycle, CommandRedelivery $redelivery, ?AgentUpgradeRollout $upgrades = null): void
    {
        $this->detectOfflineAgents();
        $redelivery->expireLeases();
        $this->timeOutCommands($lifecycle);
        ($upgrades ?? app(AgentUpgradeRollout::class))->sweep();
        $this->prune();
    }

    private function detectOfflineAgents(): void
    {
        $threshold = now()->subSeconds((int) config('fleet.offline_after_seconds', 60));

        Agent::query()
            ->where('status', AgentStatus::Online)
            ->where(fn ($q) => $q->where('last_heartbeat_at', '<', $threshold)->orWhereNull('last_heartbeat_at'))
            ->each(function (Agent $agent) use ($threshold) {
                // Conditional update: a heartbeat racing with the sweep wins.
                $updated = Agent::query()->whereKey($agent->id)
                    ->where('status', AgentStatus::Online)
                    ->where(fn ($q) => $q->where('last_heartbeat_at', '<', $threshold)->orWhereNull('last_heartbeat_at'))
                    ->update(['status' => AgentStatus::Offline]);

                if ($updated === 1) {
                    AgentWentOffline::dispatch($agent->id, $agent->organization_id, $agent->server_id, $agent->last_heartbeat_at?->toDateTimeImmutable());
                }
            });
    }

    private function timeOutCommands(CommandLifecycle $lifecycle): void
    {
        $grace = (int) config('fleet.commands.grace_seconds', 60);
        $queueTtl = (int) config('fleet.commands.queue_ttl_seconds', 3600);

        Command::query()
            ->where('status', CommandStatus::Queued)
            ->where('queued_at', '<', now()->subSeconds($queueTtl))
            ->each(fn (Command $command) => $lifecycle->fail($command, CommandStatus::TimedOut, 'The agent did not pick up the command in time.'));

        Command::query()
            ->where('status', CommandStatus::Running)
            ->whereNotNull('started_at')
            ->each(function (Command $command) use ($lifecycle, $grace) {
                if ($command->started_at?->copy()->addSeconds($command->timeout_s + $grace)->isPast()) {
                    $lifecycle->fail($command, CommandStatus::TimedOut, "No result within the {$command->timeout_s}s timeout.");
                }
            });
    }

    private function prune(): void
    {
        AgentMetric::query()->where('at', '<', now()->subHours((int) config('fleet.metrics_retention_hours', 24)))->delete();

        InstallToken::query()->where('expires_at', '<', now()->subDays(7))->delete();

        DB::table('fleet_certificates')->whereNotNull('superseded_at')->whereNull('revoked_at')
            ->where('superseded_at', '<', now()->subDays(7))
            ->update(['revoked_at' => now()]);
    }
}
