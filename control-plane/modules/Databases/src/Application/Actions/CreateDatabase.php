<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\Identifiers;
use Falak\Databases\Application\KeyValue\CreateKeyValueInstance;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateDatabase
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly CreateDatabaseUser $createUser,
        private readonly CreateKeyValueInstance $createInstance,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Redis / Valkey: an instance ({@see CreateKeyValueInstance}; `user` is ignored, the instance has its own).
     *
     * @param  array{name: string, charset?: ?string, collation?: ?string, site_id?: ?string, user?: ?array{username: string, password?: ?string, host?: ?string}, maxmemory_mb?: ?int, eviction?: ?string, persistence?: ?string}  $data
     */
    public function __invoke(DatabaseServer $server, array $data, ?string $actorId = null): Database
    {
        if ($server->engine->isKeyValue()) {
            return ($this->createInstance)($server, $data, $actorId);
        }

        $name = $data['name'];
        Identifiers::assertValid($server->engine, $name, 'name');

        if ($server->databases()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "A database named \"{$name}\" already exists on {$server->server_name}."]);
        }

        $database = DB::transaction(function () use ($server, $data, $name, $actorId) {
            $database = $server->databases()->create([
                'organization_id' => $server->organization_id,
                'server_id' => $server->server_id,
                'name' => $name,
                'charset' => $server->engine->isMysqlFamily() ? (($data['charset'] ?? null) ?: $server->engine->defaultCharset()) : null,
                'collation' => $server->engine->isMysqlFamily() ? (($data['collation'] ?? null) ?: $server->engine->defaultCollation()) : null,
                'site_id' => $data['site_id'] ?? null,
                'status' => ResourceStatus::Pending,
                'created_by' => $actorId,
            ]);

            $handle = $this->commands->dispatch(
                $server->server_id,
                'db.create',
                CommandPayloads::create($server, $database),
                (int) config('databases.timeouts.ddl', 300),
                "db.create:{$database->id}:".Str::ulid(),
                'name',
            );

            $database->forceFill(['command_id' => $handle->id])->save();

            return $database;
        });

        $this->audit->record('databases.database_created', 'database', $database->id, ['name' => $name, 'server_id' => $server->server_id], $server->organization_id);

        // The user's grant on this database is applied once db.create converges.
        if (! empty($data['user']['username'])) {
            ($this->createUser)($server, [
                'username' => $data['user']['username'],
                'password' => $data['user']['password'] ?? null,
                'host' => $data['user']['host'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'grants' => [['database_id' => $database->id, 'privileges' => ['ALL PRIVILEGES']]],
            ], $actorId);
        }

        return $database;
    }
}
