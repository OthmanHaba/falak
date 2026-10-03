<?php

namespace Kiln\Servers\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Application\MachineChecks;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\MachineCheck\Decision;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\DatabaseEngineInstalled;
use Throwable;

/**
 * Adds a database engine to a provisioned server: the engine joins the server's stack and the provisioning plan
 * converges with it (provision.apply is full desired state: the same packages and service as at creation). The
 * command's outcome registers the engine ({@see DatabaseEngineInstalled}) or takes it back out. Other modules only see
 * the engine (ServerData::databaseEngine) once it is installed.
 */
final class InstallDatabaseEngine
{
    /** engine_command_id while the install is claimed but its command not dispatched yet. */
    private const CLAIMED = 'claimed';

    public function __construct(
        private readonly ApplyProvisioningPlan $apply,
        private readonly AuditLog $audit,
        private readonly MachineChecks $checks,
    ) {}

    /**
     * @return string the provision.apply command id
     */
    public function __invoke(Server $server, string $engine, ?string $actorId = null): string
    {
        if (! array_key_exists($engine, (array) config('servers.databases', []))) {
            throw ValidationException::withMessages(['engine' => 'Unsupported database engine.']);
        }

        if (! in_array('database', $server->type->allowedComponents(), true)) {
            throw ValidationException::withMessages(['engine' => "A {$server->type->label()} cannot run a database engine."]);
        }

        // Checked and claimed under a row lock: two installs at once (the same provisioning attempt, so the same
        // idempotency key) would otherwise both pass. Until the command id is known the claim is a placeholder.
        $server = DB::transaction(function () use ($server, $engine) {
            $locked = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            if ($locked->stack->database !== null) {
                throw ValidationException::withMessages(['engine' => $locked->engine_command_id !== null
                    ? 'A database engine is already being installed on this server.'
                    : "The server already runs {$locked->stack->database}."]);
            }

            if ($locked->status !== ServerStatus::Active) {
                throw ValidationException::withMessages(['engine' => 'The server must be active (provisioned and connected).']);
            }

            $locked->forceFill(['stack' => $locked->stack->withDatabase($engine), 'engine_command_id' => self::CLAIMED])->save();

            return $locked;
        });

        try {
            // The machine check's report decides for the new engine too (another engine, its port taken).
            $decision = $this->checks->current($server)?->for('database');

            if ($decision?->decision === Decision::Block) {
                throw ValidationException::withMessages(['engine' => trim($decision->reason.' '.$decision->hint())]);
            }

            $commandId = ($this->apply)($server, markProvisioning: false);
        } catch (Throwable $e) {
            $server->forceFill(['stack' => $server->stack->withDatabase(null), 'engine_command_id' => null])->save();

            throw $e;
        }

        $server->forceFill(['engine_command_id' => $commandId])->save();
        $this->audit->record('server.database_engine_install_requested', 'server', $server->id, ['engine' => $engine, 'command_id' => $commandId], $server->organization_id, $actorId);

        return $commandId;
    }
}
