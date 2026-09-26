<?php

namespace Kiln\Terminal\Application;

use Illuminate\Support\Carbon;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Terminal\Domain\Enums\SessionStatus;
use Kiln\Terminal\Domain\Models\TerminalSession;
use Kiln\Terminal\Events\TerminalSessionClosed;
use Kiln\Terminal\Events\TerminalSessionUpdated;

/**
 * State changes of a session; every change is broadcast to live viewers, closing announces
 * TerminalSessionClosed exactly once.
 */
final class SessionTransitions
{
    public function __construct(private readonly AuditLog $audit) {}

    public function markOpen(TerminalSession $session, ?Carbon $startedAt = null): void
    {
        $dirty = false;

        if ($session->started_at === null && $startedAt !== null) {
            $session->started_at = $startedAt;
            $dirty = true;
        }

        if ($session->status === SessionStatus::Opening) {
            $session->status = SessionStatus::Open;
            $session->last_activity_at = now();
            $dirty = true;
        }

        if ($dirty) {
            $session->save();

            if ($session->wasChanged('status')) {
                $this->broadcast($session);
            }
        }
    }

    /**
     * @return bool whether the session was live (and is now closed)
     */
    public function close(TerminalSession $session, string $reason, ?int $exitCode = null, ?string $error = null, bool $failed = false): bool
    {
        if (! $session->isLive()) {
            return false;
        }

        $session->forceFill([
            'status' => $failed ? SessionStatus::Failed : SessionStatus::Closed,
            'close_reason' => $reason,
            'exit_code' => $exitCode,
            'error' => $error !== null ? mb_substr($error, 0, 250) : null,
            'closed_at' => now(),
        ])->save();

        $this->broadcast($session);

        $this->audit->record('terminal.session_closed', 'terminal_session', $session->id, [
            'server_id' => $session->server_id,
            'reason' => $reason,
            'exit_code' => $exitCode,
        ], $session->organization_id);

        TerminalSessionClosed::dispatch($session->id, $session->organization_id, $session->server_id, $session->user_id, $reason);

        return true;
    }

    public function broadcast(TerminalSession $session): void
    {
        TerminalSessionUpdated::dispatch(
            $session->id,
            $session->status->value,
            $session->close_reason,
            $session->exit_code,
            $session->error,
            $session->cols,
            $session->rows,
            $session->shared,
        );
    }
}
