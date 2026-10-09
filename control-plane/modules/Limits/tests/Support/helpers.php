<?php

use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Identity\Domain\Models\Organization;
use Falak\Servers\Domain\Models\Server;

require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';
require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';

/**
 * A server whose agent reported $memoryMb of RAM and $cpus cores.
 */
function limits_server(Organization $organization, int $memoryMb = 4096, int $cpus = 2): Server
{
    $server = databases_server($organization);
    Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $organization->id, 'facts' => ['memory_bytes' => $memoryMb * 1024 ** 2, 'cpus' => $cpus, 'features' => ['fn.v1']]]);

    return $server;
}

/**
 * @param  array{handle: mixed, payload: array<string, mixed>}  $command
 * @return array<string, list<string>>
 */
function limits_schema_errors(array $command): array
{
    return app(ProtocolSchemas::class)->validateCommand($command['handle']->type, ProtocolSchemas::toJson($command['payload']));
}
