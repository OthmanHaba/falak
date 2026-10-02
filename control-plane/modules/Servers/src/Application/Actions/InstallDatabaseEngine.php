<?php

namespace Kiln\Servers\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\DatabaseEngineInstalled;

/**
 * Adds a database engine to a provisioned server: the engine joins the server's stack and the provisioning plan
 * converges with it (provision.apply is full desired state: the same packages and service as at creation). The
 * command's outcome registers the engine ({@see DatabaseEngineInstalled}) or takes it back out.
 */
final class InstallDatabaseEngine
{
    public function __construct(
        private readonly ApplyProvisioningPlan $apply,
        private readonly AuditLog $audit,
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

        if ($server->stack->database !== null) {
            throw ValidationException::withMessages(['engine' => $server->engine_command_id !== null
                ? 'A database engine is already being installed on this server.'
                : "The server already runs {$server->stack->database}."]);
        }

        if ($server->status !== ServerStatus::Active) {
            throw ValidationException::withMessages(['engine' => 'The server must be active (provisioned and connected).']);
        }

        $server->forceFill(['stack' => $server->stack->withDatabase($engine)])->save();

        try {
            $commandId = ($this->apply)($server, markProvisioning: false);
        } catch (ValidationException $e) {
            $server->forceFill(['stack' => $server->stack->withDatabase(null)])->save();

            throw $e;
        }

        $server->forceFill(['engine_command_id' => $commandId])->save();
        $this->audit->record('server.database_engine_install_requested', 'server', $server->id, ['engine' => $engine, 'command_id' => $commandId], $server->organization_id, $actorId);

        return $commandId;
    }
}
