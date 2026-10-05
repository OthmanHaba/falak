<?php

use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Identity\Domain\Models\Organization;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;

require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';

function network_server(Organization|string $organization, array $attributes = [], ServerType $type = ServerType::Web): Server
{
    return Server::factory()->type($type)->create([
        'organization_id' => is_string($organization) ? $organization : $organization->id,
        'status' => ServerStatus::Active,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, list<string>>
 */
function network_schema_errors(string $type, array $payload): array
{
    return app(ProtocolSchemas::class)->validateCommand($type, ProtocolSchemas::toJson($payload));
}
