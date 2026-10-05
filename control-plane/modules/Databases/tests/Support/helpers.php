<?php

use Falak\Databases\Application\EngineInventory;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\StorageDriver;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Infrastructure\ObjectStorage\EndpointGuard;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Identity\Domain\Models\Organization;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;

require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';

function databases_server(Organization $organization, string $engine = 'postgresql', ServerType $type = ServerType::App, array $attributes = []): Server
{
    return Server::factory()->create([
        'organization_id' => $organization->id,
        'name' => ($type === ServerType::Database ? 'db-' : 'app-').Str::lower(Str::random(4)),
        'type' => $type,
        'stack' => ['database' => $engine],
        'ipv4' => '203.0.113.20',
        'private_ipv4' => '10.0.0.20',
        ...$attributes,
    ]);
}

function databases_engine(Organization $organization, string $engine = 'postgresql', ServerType $type = ServerType::App): DatabaseServer
{
    $server = databases_server($organization, $engine, $type);

    return app(EngineInventory::class)->sync($server->id);
}

function databases_active_db(DatabaseServer $server, string $name = 'app'): Database
{
    return $server->databases()->create([
        'organization_id' => $server->organization_id,
        'server_id' => $server->server_id,
        'name' => $name,
        'status' => ResourceStatus::Active,
    ]);
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
    app()->bind(EndpointGuard::class, fn () => new EndpointGuard((bool) config('databases.allow_private_endpoints', false), fn (string $host) => [$address]));
}
