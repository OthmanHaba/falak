<?php

namespace Falak\Security\Application\Actions;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Contracts\Firewalls;
use Falak\Security\Application\FixRunner;
use Falak\Security\Domain\Enums\FixStatus;
use Falak\Security\Domain\FixCatalogue;
use Falak\Security\Domain\Models\FixRun;
use Illuminate\Validation\ValidationException;

/**
 * Undoes an applied fix within the undo window: the agent restores its backup (security.undo), or Network deletes the
 * deny rule a "close the port" fix created.
 */
final class UndoFix
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly Firewalls $firewalls,
        private readonly FixRunner $runner,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  bool  $force  restore files changed since the fix (the agent refuses otherwise and lists them)
     */
    public function __invoke(FixRun $run, string $userId, bool $force = false): FixRun
    {
        if (! $run->canUndo()) {
            throw ValidationException::withMessages(['fix' => 'This fix can no longer be undone (undo works for '.config('security.undo_days', 7).' days).']);
        }

        $fix = FixCatalogue::find($run->fix_id);
        $this->audit->record('security.undo_requested', 'server', $run->server_id, ['fix_id' => $run->fix_id, 'run_id' => $run->id, 'backup_id' => $run->backup_id, 'force' => $force], $run->organization_id);

        if ($fix !== null && ! $fix['agent']) {
            $this->firewalls->deleteRule($run->server_id, (string) $run->backup_id);

            return $this->undone($run, $userId);
        }

        try {
            $handle = $this->agents->dispatch($run->server_id, 'security.undo', ['fix_id' => $run->fix_id, 'backup_id' => $run->backup_id, ...($force ? ['force' => true] : [])], (int) config('security.fix_timeout', 1800), "security.undo:{$run->id}");
        } catch (AgentUnavailable) {
            throw ValidationException::withMessages(['fix' => 'The server agent is not connected.']);
        }

        $run->forceFill(['status' => FixStatus::Undoing, 'undo_command_id' => $handle->id, 'undone_by' => $userId, 'error' => null])->save();

        return $run;
    }

    public function undone(FixRun $run, ?string $userId = null): FixRun
    {
        $run->forceFill(['status' => FixStatus::Undone, 'undone_at' => now(), 'undone_by' => $userId ?? $run->undone_by, 'error' => null])->save();
        $this->audit->record('security.fix_undone', 'server', $run->server_id, ['fix_id' => $run->fix_id, 'run_id' => $run->id], $run->organization_id, $run->undone_by);
        $this->runner->reaudit($run->server_id, $run->undone_by);

        return $run;
    }

    public function failed(FixRun $run, string $error): FixRun
    {
        // The backup is still there: the fix stays applied and undo can be tried again.
        $run->forceFill(['status' => FixStatus::Applied, 'error' => mb_substr("Undo failed: {$error}", 0, 1000)])->save();
        $this->audit->record('security.undo_failed', 'server', $run->server_id, ['fix_id' => $run->fix_id, 'run_id' => $run->id, 'error' => $run->error], $run->organization_id, $run->undone_by);

        return $run;
    }
}
