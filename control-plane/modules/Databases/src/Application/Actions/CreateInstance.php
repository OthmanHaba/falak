<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\Identifiers;
use Falak\Databases\Application\InstanceCertificates;
use Falak\Databases\Application\InstancePorts;
use Falak\Databases\Application\Passwords;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A new database container on a server: a Falak image of the engine (the default major unless one is chosen), its data
 * on a new sized volume, a memory limit its config is tuned to, a superuser password only the container's secret files
 * carry, a TLS certificate from the Falak CA, and a host port on 127.0.0.1. It joins its environment's Docker network,
 * where apps reach it as falak-db-<id>.
 *
 * SQL engines get a default database and a user with all privileges on it (named after the instance), created once the
 * container runs; Redis / Valkey get their keyspace (one Database row) and the `default` user. Both are pending until
 * db.instance.create succeeds.
 */
final class CreateInstance
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly ServerDirectory $servers,
        private readonly ServiceVolumes $volumes,
        private readonly InstancePorts $ports,
        private readonly InstanceCertificates $certificates,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{name: string, version?: ?string, memory_mb?: ?int, cpus?: ?float, disk_gb?: ?int, environment_id?: ?string, settings?: ?array<string, mixed>, database?: ?string, username?: ?string, site_id?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(string $organizationId, string $serverId, Engine $engine, array $data, ?string $actorId = null): DatabaseInstance
    {
        $server = $this->servers->find(strtolower($serverId));

        if ($server === null || $server->organizationId !== $organizationId) {
            throw ValidationException::withMessages(['server_id' => 'Choose a server of this organization.']);
        }

        $name = (string) $data['name'];
        Identifiers::assertInstanceName($name);

        $version = (string) (($data['version'] ?? null) ?: $engine->defaultVersion());

        if (! in_array($version, $engine->versions(), true)) {
            throw ValidationException::withMessages(['version' => "{$engine->label()} ".implode(', ', $engine->versions()).' are supported.']);
        }

        self::assertPinned($engine, $version);

        $memory = isset($data['memory_mb']) && $data['memory_mb'] !== null ? (int) $data['memory_mb'] * 1024 ** 2 : $engine->defaultMemory();
        self::assertMemory($engine, $memory);
        $disk = isset($data['disk_gb']) && $data['disk_gb'] !== null ? (int) $data['disk_gb'] * 1024 ** 3 : $engine->defaultDisk();

        if ($disk < (int) config('databases.disk.min') || $disk > (int) config('databases.disk.max')) {
            throw ValidationException::withMessages(['disk_gb' => 'Choose a disk between 1 GB and 16 TB.']);
        }

        $settings = UpdateInstance::settings($engine, (array) ($data['settings'] ?? []));

        if (DatabaseInstance::query()->where('organization_id', $organizationId)->where('server_id', $server->id)->where('name', $name)->whereNot('status', InstanceStatus::Retired)->exists()) {
            throw ValidationException::withMessages(['name' => "{$server->name} already has a database named {$name}."]);
        }

        $databaseName = $engine->isKeyValue() ? $name : (string) (($data['database'] ?? null) ?: Identifiers::sqlName($name));

        if (! $engine->isKeyValue()) {
            if (in_array(strtolower($databaseName), $engine->reservedNames(), true) || str_starts_with(strtolower($databaseName), 'pg_')) {
                $databaseName .= '_db';
            }

            Identifiers::assertValid($engine, $databaseName, 'database');
        }

        $username = $engine->isKeyValue() ? 'default' : (string) (($data['username'] ?? null) ?: $databaseName);

        if (! $engine->isKeyValue()) {
            Identifiers::assertValid($engine, $username, 'username', 'username');
        }

        $instance = DB::transaction(function () use ($organizationId, $server, $engine, $version, $name, $memory, $disk, $settings, $data, $databaseName, $username, $actorId) {
            // Serializes port allocation on the server.
            DatabaseInstance::query()->where('server_id', $server->id)->lockForUpdate()->get(['id']);

            $instance = new DatabaseInstance;
            $instance->id = strtolower((string) Str::ulid());
            $instance->forceFill([
                'organization_id' => $organizationId,
                'server_id' => $server->id,
                'server_name' => $server->name,
                'environment_id' => isset($data['environment_id']) && $data['environment_id'] !== null ? strtolower((string) $data['environment_id']) : null,
                'name' => $name,
                'engine' => $engine,
                'version' => $version,
                'image' => $engine->image($version),
                'image_digest' => $engine->pinnedDigest($version),
                'hostname' => "falak-db-{$instance->id}",
                'port' => $engine->defaultPort(),
                'host_port' => $this->ports->allocate($server->id, $this->servers->takenPorts($server->id)),
                'memory_bytes' => $memory,
                'cpus' => isset($data['cpus']) && $data['cpus'] !== null ? round((float) $data['cpus'], 2) : null,
                'settings' => $settings !== [] ? $settings : null,
                'root_password' => Passwords::generate(),
                'status' => InstanceStatus::Pending,
                'created_by' => $actorId,
            ])->save();

            $database = $instance->databases()->create([
                'organization_id' => $organizationId,
                'server_id' => $server->id,
                'name' => $databaseName,
                'charset' => $engine->defaultCharset(),
                'collation' => $engine->defaultCollation(),
                'site_id' => $data['site_id'] ?? null,
                'status' => ResourceStatus::Pending,
                'created_by' => $actorId,
            ]);

            $user = $instance->users()->create([
                'organization_id' => $organizationId,
                'server_id' => $server->id,
                'username' => $username,
                // Redis / Valkey: `default` is the instance's password.
                'password' => $engine->isKeyValue() ? $instance->root_password : Passwords::generate(),
                'host' => '%',
                'site_id' => $data['site_id'] ?? null,
                'status' => ResourceStatus::Pending,
                'created_by' => $actorId,
            ]);
            $user->grants()->create(['database_id' => $database->id, 'privileges' => ['ALL PRIVILEGES']]);

            $volume = $this->volumes->createSized($organizationId, $server->id, self::volumeName($instance), $disk, ['db-instance' => $instance->id], protected: true, actorId: $actorId);
            $this->volumes->attach($volume->id, AttachableType::Database, $database->id, '/var/lib/falak/db');
            $instance->forceFill(['volume_id' => $volume->id])->save();

            $this->dispatch($instance);

            return $instance;
        });

        $this->audit->record('databases.instance_created', 'database_instance', $instance->id, [
            'name' => $name,
            'engine' => $engine->value,
            'version' => $version,
            'server_id' => $server->id,
            'memory_bytes' => $memory,
        ], $organizationId);

        return $instance;
    }

    /**
     * db.instance.create for a saved instance (a new one, or the target of a major upgrade).
     *
     * @throws ValidationException when the agent is not connected
     */
    public function dispatch(DatabaseInstance $instance): void
    {
        $tls = $this->certificates->issue($instance);

        $handle = $this->commands->dispatch(
            $instance->server_id,
            'db.instance.create',
            CommandPayloads::instance($instance, $tls),
            (int) config('databases.timeouts.instance', 1800),
            "db.instance.create:{$instance->id}",
            'server_id',
        );

        $instance->forceFill(['command_id' => $handle->id])->save();
    }

    public static function volumeName(DatabaseInstance $instance): string
    {
        return 'db-'.substr($instance->name, 0, 40).'-'.substr($instance->id, -6);
    }

    /**
     * Images only run pinned by the digest this release ships (config databases.digests).
     *
     * @throws ValidationException
     */
    public static function assertPinned(Engine $engine, string $version): void
    {
        if ($engine->pinnedDigest($version) === null) {
            throw ValidationException::withMessages(['version' => "This Falak release ships no pinned image of {$engine->label()} {$version} (db-image-digests.json)."]);
        }
    }

    /**
     * @throws ValidationException
     */
    public static function assertMemory(Engine $engine, int $memory): void
    {
        $min = $engine->minMemory();
        $max = (int) config('databases.memory.max');

        if ($memory < $min || $memory > $max) {
            throw ValidationException::withMessages(['memory_mb' => "{$engine->label()} needs between ".intdiv($min, 1024 ** 2).' MB and '.intdiv($max, 1024 ** 2).' MB of memory.']);
        }
    }
}
