<?php

namespace Kiln\Terminal\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Terminal\Application\SessionTransitions;
use Kiln\Terminal\Domain\Models\TerminalSession;

/**
 * The terminal.open command ended: the shell exited, the agent closed it (idle / timeout / close request),
 * or it failed to start.
 */
final class HandleTerminalCommandOutcome implements ShouldQueue
{
    public const REASONS = ['exited', 'closed', 'idle', 'timeout'];

    public function __construct(private readonly SessionTransitions $transitions) {}

    public function handleFinished(CommandFinished $event): void
    {
        if ($event->type !== 'terminal.open' || ! $session = $this->session($event->commandId, $event->organizationId)) {
            return;
        }

        $reason = $event->result['reason'] ?? 'exited';
        // The structured result is authoritative: idle/closed sessions have no shell exit code.
        $exitCode = $event->result !== null ? (isset($event->result['exit_code']) ? (int) $event->result['exit_code'] : null) : $event->exitCode;

        $this->transitions->close($session, in_array($reason, self::REASONS, true) ? $reason : 'exited', $exitCode);
    }

    public function handleFailed(CommandFailed $event): void
    {
        if ($event->type !== 'terminal.open' || ! $session = $this->session($event->commandId, $event->organizationId)) {
            return;
        }

        $reason = $event->result['reason'] ?? null;

        // A shell exiting non-zero is a normal end of session, not a failure.
        if (in_array($reason, self::REASONS, true)) {
            $exitCode = isset($event->result['exit_code']) ? (int) $event->result['exit_code'] : $event->exitCode;
            $this->transitions->close($session, $reason, $exitCode);

            return;
        }

        if ($event->status === 'timed_out') {
            $this->transitions->close($session, 'timeout', $event->exitCode);

            return;
        }

        $this->transitions->close($session, 'failed', $event->exitCode, $event->error ?: "Command {$event->status}", failed: true);
    }

    private function session(string $commandId, string $organizationId): ?TerminalSession
    {
        return TerminalSession::query()
            ->where('command_id', $commandId)
            ->where('organization_id', $organizationId)
            ->first();
    }
}
