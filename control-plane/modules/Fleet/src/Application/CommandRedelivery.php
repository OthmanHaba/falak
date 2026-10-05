<?php

namespace Falak\Fleet\Application;

use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Fleet\Infrastructure\Signals\CommandSignal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Commands whose delivery was lost. Every falak-agent process identifies itself with a session id
 * (X-Falak-Agent-Session); a command remembers the session it was delivered to. A delivery is lost when
 *
 *  - the agent comes back with a new session (it restarted: upgrade, crash, reboot) while the command is still
 *    delivered or running under an older one — including a long-poll the old process abandoned, which the server
 *    kept waiting on and answered after the process was gone; or
 *  - the command stays delivered for longer than the lease (`fleet.commands.lease_seconds`) without the agent
 *    reporting it as started, running (heartbeat) or finished.
 *
 * Redeliverable types (`x-falak-redeliverable` in the command schema: `*.apply` state, read-only commands) are
 * queued again; the agent answers a command it already finished from its journal, so nothing runs twice. Any
 * other type fails with a clear error, so the deployment (or other operation) waiting on it fails fast instead
 * of hanging.
 */
final class CommandRedelivery
{
    public function __construct(
        private readonly CommandLifecycle $lifecycle,
        private readonly ProtocolSchemas $schemas,
        private readonly CommandSignal $signal,
    ) {}

    /**
     * Record the agent process a request came from. Returns true when this request switched the agent to a new
     * session; commands delivered to the previous process(es) are then redelivered or failed.
     */
    public function observeSession(Agent $agent, ?string $session): bool
    {
        $previous = $agent->session_id;

        if ($previous === $session) {
            return false;
        }

        // Conditional: the new process's first poll and heartbeat may race; exactly one of them reconciles.
        $switched = DB::table('fleet_agents')->where('id', $agent->id)
            ->where(fn ($q) => $previous === null ? $q->whereNull('session_id') : $q->where('session_id', $previous))
            ->update(['session_id' => $session, 'session_started_at' => now(), 'updated_at' => now()]);

        $agent->forceFill(['session_id' => $session, 'session_started_at' => now()])->syncOriginal();

        if ($switched !== 1) {
            return false;
        }

        $this->orphanedBy($agent->id, $session)->each(function (Command $command) {
            if ($command->status === CommandStatus::Delivered) {
                $this->redeliverOrFail($command, CommandStatus::Failed,
                    "The agent restarted before running the command. {$command->type} is not safe to run twice, so it was not delivered again.");

                return;
            }

            // Running under the previous process: the restart interrupted it, or its result was lost.
            $this->redeliverOrFail($command, CommandStatus::TimedOut,
                'The agent restarted while running the command and did not report a result.');
        });

        return true;
    }

    /**
     * Delivered commands that were never acknowledged within the lease.
     */
    public function expireLeases(): void
    {
        $lease = max(10, (int) config('fleet.commands.lease_seconds', 90));

        Command::query()
            ->where('status', CommandStatus::Delivered)
            ->where('delivered_at', '<', now()->subSeconds($lease))
            ->each(fn (Command $command) => $this->redeliverOrFail($command, CommandStatus::TimedOut,
                "The agent did not start the command within {$lease}s of receiving it (it may have restarted). {$command->type} is not safe to run twice, so it was not delivered again."));
    }

    /**
     * Queue a lost command again when its type allows it (up to max_attempts deliveries), else fail it with
     * $status (TimedOut when a late result may still arrive: it then overrides the failure).
     */
    private function redeliverOrFail(Command $command, CommandStatus $status, string $error): void
    {
        if (! $this->schemas->isRedeliverable($command->type)) {
            $this->lifecycle->fail($command, $status, $error);

            return;
        }

        $maxAttempts = (int) config('fleet.commands.max_attempts', 5);

        if ($command->attempts >= $maxAttempts) {
            $this->lifecycle->fail($command, CommandStatus::Failed, "The agent did not acknowledge the command after {$command->attempts} deliveries.");

            return;
        }

        $requeued = Command::query()->whereKey($command->id)->where('status', $command->status)
            ->update([
                'status' => CommandStatus::Queued,
                'delivered_at' => null,
                'delivered_session' => null,
                'started_at' => null,
            ]);

        if ($requeued === 1) {
            $this->signal->notify($command->agent_id);
        }
    }

    /**
     * @return Builder<Command>
     */
    private function orphanedBy(string $agentId, ?string $session): Builder
    {
        return Command::query()
            ->where('agent_id', $agentId)
            ->whereIn('status', [CommandStatus::Delivered, CommandStatus::Running])
            ->where(fn ($q) => $session === null
                ? $q->whereNotNull('delivered_session')
                : $q->whereNull('delivered_session')->orWhere('delivered_session', '!=', $session));
    }
}
