<?php

namespace Kiln\Fleet\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Kiln\Fleet\Application\CommandLifecycle;
use Kiln\Fleet\Contracts\AgentStatus;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\AgentMetric;
use Kiln\Fleet\Domain\Models\Command;
use Kiln\Fleet\Domain\Models\InstallToken;
use Kiln\Fleet\Events\AgentWentOffline;
use Kiln\Fleet\Infrastructure\Signals\CommandSignal;

/**
 * Scheduled every minute: offline detection, command redelivery / timeouts / expiry, and pruning.
 */
final class SweepFleet implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 120;

    public function handle(CommandLifecycle $lifecycle, CommandSignal $signal): void
    {
        $this->detectOfflineAgents();
        $this->redeliverStalledCommands($lifecycle, $signal);
        $this->timeOutCommands($lifecycle);
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

    private function redeliverStalledCommands(CommandLifecycle $lifecycle, CommandSignal $signal): void
    {
        $cutoff = now()->subSeconds((int) config('fleet.commands.redeliver_after_seconds', 90));
        $maxAttempts = (int) config('fleet.commands.max_attempts', 5);

        Command::query()
            ->where('status', CommandStatus::Delivered)
            ->where('delivered_at', '<', $cutoff)
            ->each(function (Command $command) use ($lifecycle, $signal, $maxAttempts) {
                if ($command->attempts >= $maxAttempts) {
                    $lifecycle->fail($command, CommandStatus::Failed, "The agent did not acknowledge the command after {$command->attempts} deliveries.");

                    return;
                }

                $requeued = Command::query()->whereKey($command->id)->where('status', CommandStatus::Delivered)
                    ->update(['status' => CommandStatus::Queued, 'delivered_at' => null]);

                if ($requeued === 1) {
                    $signal->notify($command->agent_id);
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
