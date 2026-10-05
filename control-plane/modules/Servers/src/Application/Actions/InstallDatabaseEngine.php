<?php

namespace Falak\Servers\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Application\MachineChecks;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\MachineCheck\Decision;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Events\DatabaseEngineInstalled;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Adds a database engine (PostgreSQL, MySQL, MariaDB) or a cache engine (Redis, Valkey) to a provisioned server: the
 * engine joins the server's stack and the provisioning plan converges with it (provision.apply is full desired state:
 * the same packages and service as at creation). The command's outcome registers the engine
 * ({@see DatabaseEngineInstalled}) or takes it back out. Other modules only see the engine (ServerData::databaseEngine
 * / cacheEngine) once it is installed. One engine install runs at a time per server.
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
        $kind = match (true) {
            array_key_exists($engine, (array) config('servers.databases', [])) => 'database',
            array_key_exists($engine, (array) config('servers.caches', [])) => 'cache',
            default => throw ValidationException::withMessages(['engine' => 'Unsupported database engine.']),
        };
        $label = (string) (config("servers.{$kind}s.{$engine}.label") ?? $engine);

        if (! in_array($kind, $server->type->allowedComponents(), true)) {
            throw ValidationException::withMessages(['engine' => $kind === 'cache'
                ? "A {$server->type->label()} cannot run Redis or Valkey."
                : "A {$server->type->label()} cannot run a database engine."]);
        }

        if ($kind === 'cache' && ! in_array($engine, $server->installableCaches(), true)) {
            throw ValidationException::withMessages(['engine' => "{$label} is not available on {$server->osLabel()}."]);
        }

        // Checked and claimed under a row lock: two installs at once (the same provisioning attempt, so the same
        // idempotency key) would otherwise both pass. Until the command id is known the claim is a placeholder.
        $server = DB::transaction(function () use ($server, $engine, $kind) {
            $locked = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            if ($locked->engine_command_id !== null) {
                throw ValidationException::withMessages(['engine' => $locked->installing('cache')
                    ? 'A cache engine is already being installed on this server.'
                    : 'A database engine is already being installed on this server.']);
            }

            $current = $kind === 'cache' ? $locked->stack->cache : $locked->stack->database;

            if ($current !== null) {
                throw ValidationException::withMessages(['engine' => "The server already runs {$current}."]);
            }

            if ($locked->status !== ServerStatus::Active) {
                throw ValidationException::withMessages(['engine' => 'The server must be active (provisioned and connected).']);
            }

            $locked->forceFill([
                'stack' => $kind === 'cache' ? $locked->stack->withCache($engine) : $locked->stack->withDatabase($engine),
                'engine_command_id' => self::CLAIMED,
                'engine_install_kind' => $kind === 'cache' ? 'cache' : null,
            ])->save();

            return $locked;
        });

        try {
            // The machine check's report decides for the new engine too (another engine, its port taken).
            $decision = $this->checks->current($server)?->for($kind);

            if ($decision?->decision === Decision::Block) {
                throw ValidationException::withMessages(['engine' => trim($decision->reason.' '.$decision->hint())]);
            }

            $commandId = ($this->apply)($server, markProvisioning: false);
        } catch (Throwable $e) {
            $server->forceFill([
                'stack' => $kind === 'cache' ? $server->stack->withCache(null) : $server->stack->withDatabase(null),
                'engine_command_id' => null,
                'engine_install_kind' => null,
            ])->save();

            throw $e;
        }

        $server->forceFill(['engine_command_id' => $commandId])->save();
        $this->audit->record('server.database_engine_install_requested', 'server', $server->id, ['engine' => $engine, 'kind' => $kind, 'command_id' => $commandId], $server->organization_id, $actorId);

        return $commandId;
    }
}
