<?php

use Kiln\Fleet\Contracts\Data\CommandHandle;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Terminal\Domain\Models\TerminalSession;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';

function terminal_server(Organization $organization, array $attributes = []): Server
{
    return Server::factory()->create(['organization_id' => $organization->id, 'name' => 'web-1', ...$attributes]);
}

/**
 * Open a session through the HTTP endpoint as the authenticated user.
 */
function terminal_open(Server $server, array $data = []): TerminalSession
{
    test()->post("/terminal/servers/{$server->id}/sessions", $data)->assertRedirect();

    return TerminalSession::query()->latest('id')->firstOrFail();
}

function terminal_b64(string $bytes): string
{
    return base64_encode($bytes);
}

/**
 * @return array{handle: CommandHandle, payload: array<string, mixed>, timeout: int}
 */
function terminal_open_command(FakeAgentGateway $agents): array
{
    return $agents->last('terminal.open');
}
