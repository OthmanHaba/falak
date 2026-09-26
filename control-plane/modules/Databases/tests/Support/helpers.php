<?php

use Kiln\Databases\Application\EngineInventory;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Enums\StorageDriver;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\StorageProvider;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Models\Server;

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
        'bucket' => 'kiln-backups',
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
