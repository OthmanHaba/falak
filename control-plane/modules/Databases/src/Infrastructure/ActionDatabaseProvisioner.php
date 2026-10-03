<?php

namespace Kiln\Databases\Infrastructure;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Application\Actions\CreateDatabase;
use Kiln\Databases\Application\Actions\DeleteDatabase;
use Kiln\Databases\Application\EngineInventory;
use Kiln\Databases\Contracts\Data\DatabaseData;
use Kiln\Databases\Contracts\DatabaseDirectory;
use Kiln\Databases\Contracts\DatabaseProvisioner;
use Kiln\Databases\Domain\Enums\Engine;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use LogicException;

final class ActionDatabaseProvisioner implements DatabaseProvisioner
{
    public function __construct(
        private readonly EngineInventory $inventory,
        private readonly CreateDatabase $createDatabase,
        private readonly DatabaseDirectory $directory,
        private readonly DeleteDatabase $deleteDatabase,
    ) {}

    public function create(string $organizationId, string $serverId, string $engine, string $name, ?string $actorId = null, array $options = []): DatabaseData
    {
        $requested = Engine::tryFrom(strtolower($engine))
            ?? throw ValidationException::withMessages(['engine' => 'Unknown database engine.']);

        $server = DatabaseServer::query()->where('server_id', $serverId)->where('engine', $requested)->first()
            ?? $this->inventory->sync($serverId, $requested);

        if ($server === null || $server->organization_id !== $organizationId) {
            $other = DatabaseServer::query()->where('server_id', $serverId)->where('organization_id', $organizationId)
                ->whereIn('engine', $requested->kind()->values())->first();

            throw ValidationException::withMessages($other !== null
                ? ['engine' => "{$other->server_name} runs {$other->engine->label()}, not {$requested->label()}."]
                : ['server_id' => $requested->isKeyValue() ? "The server does not run {$requested->label()}." : 'The server has no database engine.']);
        }

        $database = ($this->createDatabase)($server, $requested->isKeyValue()
            ? ['name' => $name, ...array_intersect_key($options, array_flip(['maxmemory_mb', 'eviction', 'persistence']))]
            : ['name' => $name, 'user' => ['username' => $this->username($server, $name)]], $actorId);

        return $this->directory->find($database->id) ?? throw new LogicException('Created database not found.');
    }

    public function delete(string $databaseId): void
    {
        if ($database = Database::query()->find(strtolower($databaseId))) {
            ($this->deleteDatabase)($database);
        }
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
