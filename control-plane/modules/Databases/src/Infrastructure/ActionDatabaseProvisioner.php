<?php

namespace Kiln\Databases\Infrastructure;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Application\Actions\CreateDatabase;
use Kiln\Databases\Application\EngineInventory;
use Kiln\Databases\Contracts\Data\DatabaseData;
use Kiln\Databases\Contracts\DatabaseDirectory;
use Kiln\Databases\Contracts\DatabaseProvisioner;
use Kiln\Databases\Domain\Enums\Engine;
use Kiln\Databases\Domain\Models\DatabaseServer;
use LogicException;

final class ActionDatabaseProvisioner implements DatabaseProvisioner
{
    public function __construct(
        private readonly EngineInventory $inventory,
        private readonly CreateDatabase $createDatabase,
        private readonly DatabaseDirectory $directory,
    ) {}

    public function create(string $organizationId, string $serverId, string $engine, string $name, ?string $actorId = null): DatabaseData
    {
        $requested = Engine::tryFrom(strtolower($engine))
            ?? throw ValidationException::withMessages(['engine' => $engine === 'redis' ? 'Redis services are not supported yet.' : 'Unknown database engine.']);

        $server = DatabaseServer::query()->where('server_id', $serverId)->first() ?? $this->inventory->sync($serverId);

        if ($server === null || $server->organization_id !== $organizationId) {
            throw ValidationException::withMessages(['server_id' => 'The server has no database engine.']);
        }

        if ($server->engine !== $requested) {
            throw ValidationException::withMessages(['engine' => "{$server->server_name} runs {$server->engine->label()}, not {$requested->label()}."]);
        }

        $database = ($this->createDatabase)($server, [
            'name' => $name,
            'user' => ['username' => $this->username($server, $name)],
        ], $actorId);

        return $this->directory->find($database->id) ?? throw new LogicException('Created database not found.');
    }

    /** The database name, suffixed when the server already has a user of that name. */
    private function username(DatabaseServer $server, string $name): string
    {
        $base = substr($name, 0, 58);
        $username = $base;

        for ($i = 2; $server->users()->where('username', $username)->exists(); $i++) {
            $username = "{$base}_{$i}";
        }

        return $username;
    }
}
