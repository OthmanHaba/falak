<?php

namespace Kiln\Databases\Application\KeyValue;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Databases\Application\Identifiers;
use Kiln\Databases\Application\Passwords;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Creates a Redis / Valkey instance: its own port, settings and `default` user (generated password), then
 * db.redis.apply. The instance is `pending` until the agent confirms (then DatabaseCreated). Needs an agent with the
 * db.redis feature.
 */
final class CreateKeyValueInstance
{
    public const FEATURE = 'db.redis';

    public function __construct(
        private readonly AgentDirectory $agents,
        private readonly KeyValuePorts $ports,
        private readonly KeyValueSettings $settings,
        private readonly ApplyKeyValueInstance $apply,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{name: string, site_id?: ?string, maxmemory_mb?: ?int, eviction?: ?string, persistence?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(DatabaseServer $server, array $data, ?string $actorId = null): Database
    {
        $name = $data['name'];
        Identifiers::assertValid($server->engine, $name, 'name');

        if (! ($this->agents->forServer($server->server_id)?->supports(self::FEATURE) ?? false)) {
            throw ValidationException::withMessages(['server_id' => "Update the agent on {$server->server_name} first: {$server->engine->label()} instances need a newer agent (feature db.redis)."]);
        }

        $settings = $this->settings->resolve($server->server_id, $data, KeyValueSettings::defaults(), capDefault: true);

        $database = DB::transaction(function () use ($server, $data, $name, $settings, $actorId) {
            // Serializes creations on the server: the port is picked from what the locked rows' instances hold.
            DatabaseServer::query()->where('server_id', $server->server_id)->lockForUpdate()->get();

            if ($server->databases()->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => "An instance named \"{$name}\" already exists on {$server->server_name}."]);
            }

            $database = $server->databases()->create([
                'organization_id' => $server->organization_id,
                'server_id' => $server->server_id,
                'name' => $name,
                'port' => $this->ports->allocate($server->server_id),
                'settings' => $settings,
                'site_id' => $data['site_id'] ?? null,
                'status' => ResourceStatus::Pending,
                'created_by' => $actorId,
            ]);

            // The `default` user holding requirepass. Its row is named after the instance (usernames are unique per
            // engine row); clients always authenticate as `default`.
            $user = $server->users()->create([
                'organization_id' => $server->organization_id,
                'server_id' => $server->server_id,
                'username' => $name,
                'password' => Passwords::generate(),
                'host' => '%',
                'site_id' => $data['site_id'] ?? null,
                'status' => ResourceStatus::Pending,
                'created_by' => $actorId,
            ]);
            $user->grants()->create(['database_id' => $database->id, 'privileges' => ['ALL PRIVILEGES']]);

            ($this->apply)($database);

            return $database->refresh();
        });

        $this->audit->record('databases.database_created', 'database', $database->id, [
            'name' => $name, 'server_id' => $server->server_id, 'engine' => $server->engine->value, 'port' => $database->port,
        ], $server->organization_id);

        return $database;
    }
}
