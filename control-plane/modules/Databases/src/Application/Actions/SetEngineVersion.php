<?php

namespace Kiln\Databases\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Application\EngineInventory;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Manually pins the engine version / port shown in connection details (when detection is wrong).
 * A null version returns to automatic detection.
 */
final class SetEngineVersion
{
    public function __construct(
        private readonly EngineInventory $inventory,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(DatabaseServer $server, ?string $version, int $port): DatabaseServer
    {
        if ($version !== null && ! in_array($version, (array) config("databases.versions.{$server->engine->value}", []), true)) {
            throw ValidationException::withMessages(['version' => 'Unsupported '.$server->engine->label().' version.']);
        }

        $server->forceFill([
            'version' => $version ?? $server->version,
            'version_source' => $version === null ? 'default' : 'manual',
            'port' => $port,
        ])->save();

        if ($version === null) {
            $server = $this->inventory->sync($server->server_id) ?? $server;
        }

        $this->audit->record('databases.engine_updated', 'database_server', $server->id, ['version' => $server->version, 'port' => $port], $server->organization_id);

        return $server;
    }
}
