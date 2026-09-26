<?php

use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Models\Server;

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
