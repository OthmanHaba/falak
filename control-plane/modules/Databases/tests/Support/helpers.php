<?php

use Falak\Databases\Application\Passwords;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\StorageDriver;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Identity\Domain\Models\Organization;
use Falak\Kernel\Network\EndpointGuard;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';

function databases_server(Organization $organization, ServerType $type = ServerType::App, array $attributes = []): Server
{
    return Server::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'app-'.Str::lower(Str::random(4)),
        'type' => $type,
        'ipv4' => '203.0.113.20',
        'private_ipv4' => '10.0.0.20',
        ...$attributes,
    ]);
}

/**
 * A running database container (no databases or users of its own; see databases_active_db / databases_service).
 */
function databases_instance(Organization $organization, string $engine = 'postgresql', ?Server $server = null, array $attributes = []): DatabaseInstance
{
    $server ??= databases_server($organization);
    $engine = Engine::from($engine);
    $instance = new DatabaseInstance;
    $instance->id = strtolower((string) Str::ulid());

    $instance->forceFill([
        'organization_id' => $organization->id,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'name' => 'db-'.Str::lower(Str::random(5)),
        'engine' => $engine,
        'version' => $engine->defaultVersion(),
        'image' => $engine->image($engine->defaultVersion()),
        'image_digest' => 'sha256:'.str_repeat('a', 64),
        'hostname' => "falak-db-{$instance->id}",
        'port' => $engine->defaultPort(),
        'host_port' => 20000 + DatabaseInstance::query()->where('server_id', $server->id)->count(),
        'memory_bytes' => $engine->defaultMemory(),
        'volume_id' => strtolower((string) Str::ulid()),
        'root_password' => Passwords::generate(),
        'status' => InstanceStatus::Active,
        ...$attributes,
    ])->save();

    return $instance;
}

function databases_active_db(DatabaseInstance $instance, string $name = 'app'): Database
{
    return $instance->databases()->create([
        'organization_id' => $instance->organization_id,
        'server_id' => $instance->server_id,
        'name' => $name,
        'status' => ResourceStatus::Active,
    ]);
}

function databases_active_user(DatabaseInstance $instance, string $username, ?Database $database = null): DatabaseUser
{
    $user = $instance->users()->create([
        'organization_id' => $instance->organization_id,
        'server_id' => $instance->server_id,
        'username' => $username,
        'password' => $instance->engine->isKeyValue() ? $instance->root_password : Passwords::generate(),
        'host' => '%',
        'status' => ResourceStatus::Active,
    ]);

    if ($database !== null) {
        $user->grants()->create(['database_id' => $database->id, 'privileges' => ['ALL PRIVILEGES']]);
    }

    return $user;
}

/**
 * A running container with its default database and user (what the canvas creates), all active.
 *
 * @return array{0: Database, 1: DatabaseUser, 2: DatabaseInstance}
 */
function databases_service(Organization $organization, string $engine = 'postgresql', string $name = 'app', ?Server $server = null, array $attributes = []): array
{
    $instance = databases_instance($organization, $engine, $server, ['name' => str_replace('_', '-', $name), ...$attributes]);
    $database = databases_active_db($instance, $name);
    $user = databases_active_user($instance, $instance->engine->isKeyValue() ? 'default' : $name, $database);

    return [$database, $user, $instance];
}

function databases_provider(Organization $organization, array $attributes = []): StorageProvider
{
    return StorageProvider::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Backups '.Str::random(4),
        'driver' => StorageDriver::S3,
        'endpoint' => 'https://s3.eu-central-1.amazonaws.com',
        'region' => 'eu-central-1',
        'bucket' => 'falak-backups',
        'prefix' => 'acme',
        'path_style' => false,
        'access_key_id' => 'AKIAEXAMPLEKEY123456',
        'secret_access_key' => 'super-secret-access-key-value',
        ...$attributes,
    ]);
}

/**
 * @return array<string, list<string>>
 */
function databases_schema_errors(array $command): array
{
    return app(ProtocolSchemas::class)->validateCommand($command['handle']->type, ProtocolSchemas::toJson($command['payload']));
}

/**
 * Resolve every storage hostname to a public address (tests never touch real DNS).
 */
function databases_fake_dns(string $address = '93.184.216.34'): void
{
    app()->instance(EndpointGuard::class, new EndpointGuard(fn (string $host) => [$address]));
}
