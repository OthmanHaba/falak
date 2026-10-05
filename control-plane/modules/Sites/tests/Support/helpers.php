<?php

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\Data\AgentInfo;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Stack\Stack;
use Falak\Sites\Tests\Support\FakeSourceControlGateway;
use Falak\Sites\Tests\Support\RecordingAgentGateway;
use Falak\SourceControl\Contracts\SourceControlGateway;

/**
 * Bind an AgentGateway double that schema-validates every dispatched payload.
 */
function sites_fake_agents(): RecordingAgentGateway
{
    $schemas = app(ProtocolSchemas::class);
    $gateway = new RecordingAgentGateway(fn (string $type, mixed $payload) => $schemas->hasCommand($type)
        ? $schemas->validateCommand($type, ProtocolSchemas::toJson($payload))
        : ['/' => ["unknown command type {$type}"]]);

    app()->instance(AgentGateway::class, $gateway);

    return $gateway;
}

function sites_fake_source_control(): FakeSourceControlGateway
{
    $fake = new FakeSourceControlGateway;
    app()->instance(SourceControlGateway::class, $fake);

    return $fake;
}

/**
 * Memory facts reported by fake agents, keyed by server id.
 *
 * @param  array<string, int>  $memory
 */
function sites_fake_agent_memory(array $memory): void
{
    app()->instance(AgentDirectory::class, new class($memory) implements AgentDirectory
    {
        /** @param array<string, int> $memory */
        public function __construct(private array $memory) {}

        public function forServer(string $serverId): ?AgentInfo
        {
            return isset($this->memory[$serverId])
                ? new AgentInfo('agent', $serverId, AgentStatus::Online, '1.0.0', 'host', 'amd64', ['memory_bytes' => $this->memory[$serverId]], [], new DateTimeImmutable, null, null)
                : null;
        }

        public function forServers(array $serverIds): array
        {
            return array_filter(array_combine($serverIds, array_map(fn ($id) => $this->forServer($id), $serverIds)));
        }

        public function metrics(string $serverId, DateTimeInterface $since): array
        {
            return [];
        }
    });
}

/**
 * An active site-hosting server with installed PHP versions.
 *
 * @param  list<string>  $php
 */
function sites_server(string $organizationId, array $attributes = [], array $php = ['8.4'], string $phpRuntime = 'frankenphp', bool $docker = false): Server
{
    $server = Server::factory()->create([
        'organization_id' => $organizationId,
        'type' => ServerType::Web,
        'stack' => new Stack($phpRuntime, $php, $php[0] ?? null, '22', null, null, $docker),
        'private_ipv4' => '10.0.0.'.random_int(2, 250),
        ...$attributes,
    ]);

    foreach ($php as $index => $version) {
        PhpVersion::query()->create([
            'server_id' => $server->id,
            'version' => $version,
            'status' => PhpVersionStatus::Installed,
            'is_default' => $index === 0,
            'ini' => PhpVersion::DEFAULT_INI,
            'fpm' => PhpVersion::defaultFpm(4 * 1024 ** 3),
        ]);
    }

    return $server;
}

/**
 * Simulate the agent finishing a recorded command.
 *
 * @param  array{id: string, server_id: string, type: string, key: string}  $command
 */
function sites_finish(array $command, bool $success = true, ?string $error = null, int $exitCode = 0): void
{
    $organizationId = (string) Server::query()->whereKey($command['server_id'])->value('organization_id');

    if ($success) {
        CommandFinished::dispatch($command['id'], $organizationId, $command['server_id'], $command['type'], $command['key'], $exitCode, null);
    } else {
        CommandFailed::dispatch($command['id'], $organizationId, $command['server_id'], $command['type'], $command['key'], 'failed', $error, $exitCode ?: 1);
    }
}

/**
 * Valid site creation input.
 *
 * @param  list<string>  $serverIds
 * @return array<string, mixed>
 */
function sites_input(array $serverIds, array $overrides = []): array
{
    return array_replace([
        'name' => 'Shop',
        'framework' => 'laravel',
        'runtime' => 'frankenphp',
        'php_version' => '8.4',
        'server_ids' => $serverIds,
    ], $overrides);
}
