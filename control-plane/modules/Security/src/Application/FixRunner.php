<?php

namespace Falak\Security\Application;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Contracts\Firewalls;
use Falak\Security\Application\Actions\StartAudit;
use Falak\Security\Domain\Enums\FixStatus;
use Falak\Security\Domain\FixCatalogue;
use Falak\Security\Domain\Models\FixRun;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerSshKeys;
use Throwable;

/**
 * Runs fixes: agent fixes as security.fix commands (settled by HandleCommandOutcome), control-plane fixes right here
 * through Network's Firewalls contract. A "Fix all safe" batch runs one fix after the other; the server is audited
 * again once nothing is left to run.
 */
final class FixRunner
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly Firewalls $firewalls,
        private readonly ServerDirectory $servers,
        private readonly AuditLog $audit,
        private readonly StartAudit $startAudit,
        private readonly ServerSshKeys $keys,
    ) {}

    /**
     * Start a queued (or new) fix run.
     *
     * @param  array<string, mixed>  $options  e.g. ["reboot_at" => "04:00"]
     */
    public function start(FixRun $run, array $options = []): FixRun
    {
        $fix = FixCatalogue::find($run->fix_id);

        if ($fix === null) {
            return $this->settle($run, FixStatus::Failed, error: 'Unknown fix.');
        }

        if (! $fix['agent']) {
            return $this->controlPlane($run, $fix);
        }

        if ($run->fix_id === 'ssh.harden') {
            // The agent doesn't count the keys Falak installs for its own falak user as a way in for people.
            $options['managed_keys'] = (object) $this->keys->authorizedKeys($run->server_id);
        }

        try {
            $handle = $this->agents->dispatch($run->server_id, 'security.fix', ['fix_id' => $run->fix_id, ...$options], (int) config('security.fix_timeout', 1800), "security.fix:{$run->id}");
        } catch (AgentUnavailable) {
            return $this->settle($run, FixStatus::Failed, error: 'The server agent is not connected.');
        }

        $run->forceFill(['status' => FixStatus::Applying, 'command_id' => $handle->id])->save();

        return $run;
    }

    /**
     * Record a fix's outcome, then run the next fix of its batch (or audit the server again).
     */
    public function settle(FixRun $run, FixStatus $status, ?string $message = null, ?string $error = null, ?string $backupId = null, ?bool $undoable = null): FixRun
    {
        $run->forceFill([
            'status' => $status,
            'message' => $message !== null ? mb_substr($message, 0, 1000) : $run->message,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'backup_id' => $backupId ?? $run->backup_id,
            'undoable' => $undoable ?? $run->undoable,
            'applied_at' => in_array($status, [FixStatus::Applied, FixStatus::Unchanged], true) ? now() : $run->applied_at,
        ])->save();

        $this->audit->record($status === FixStatus::Failed ? 'security.fix_failed' : 'security.fix_applied', 'server', $run->server_id, [
            'fix_id' => $run->fix_id,
            'run_id' => $run->id,
            'status' => $status->value,
            'backup_id' => $run->backup_id,
            'error' => $run->error,
        ], $run->organization_id, $run->applied_by);

        $this->next($run);

        return $run;
    }

    /**
     * After a fix of a batch: start the next queued one; when nothing is left, audit the server again.
     */
    public function next(FixRun $after): void
    {
        if ($after->batch_id !== null) {
            $queued = FixRun::query()
                ->where('batch_id', $after->batch_id)
                ->where('status', FixStatus::Queued)
                ->orderBy('position')
                ->first();

            if ($queued !== null) {
                $this->start($queued);

                return;
            }
        }

        $this->reaudit($after->server_id, $after->applied_by);
    }

    public function reaudit(string $serverId, ?string $userId): void
    {
        if ($server = $this->servers->find($serverId)) {
            ($this->startAudit)($server, 'fix', $userId);
        }
    }

    /**
     * @param  array{id: string, base: string, params: list<string>, label: string, disruptive: bool, agent: bool, undoable: bool}  $fix
     */
    private function controlPlane(FixRun $run, array $fix): FixRun
    {
        $run->forceFill(['status' => FixStatus::Applying])->save();

        try {
            if ($fix['base'] === 'firewall.apply') {
                $this->firewalls->reapply($run->server_id);

                return $this->settle($run, FixStatus::Applied, 'The firewall ruleset is being applied again.', undoable: false);
            }

            [$protocol, $port] = FixCatalogue::port($fix['params']) ?? throw new \InvalidArgumentException('Invalid port.');
            $rule = $this->firewalls->denyPort($run->server_id, $protocol, $port, "Closed by the security baseline ({$protocol}/{$port})");

            return $this->settle($run, FixStatus::Applied, "A firewall rule denies {$protocol}/{$port} from anywhere.", backupId: $rule, undoable: true);
        } catch (Throwable $e) {
            report($e);

            return $this->settle($run, FixStatus::Failed, error: $e->getMessage());
        }
    }
}
