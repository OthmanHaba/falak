<?php

use Falak\Databases\Contracts\BackupPosture;
use Falak\Databases\Contracts\Data\DatabaseBackupPosture;
use Falak\Identity\Domain\Models\Organization;
use Falak\Security\Application\Actions\StartAudit;
use Falak\Security\Domain\Models\Audit;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';

function security_server(Organization|string $organization, array $attributes = []): Server
{
    return Server::factory()->create([
        'organization_id' => is_string($organization) ? $organization : $organization->id,
        'status' => ServerStatus::Active,
        ...$attributes,
    ]);
}

/**
 * One agent check.
 *
 * @return array<string, mixed>
 */
function security_check(string $id, string $status = 'pass', string $severity = 'medium', ?string $fixId = null, string $area = 'ssh', ?string $evidence = null): array
{
    return array_filter([
        'id' => $id,
        'title' => "Check {$id}",
        'area' => $area,
        'status' => $status,
        'severity' => $severity,
        'evidence' => $evidence ?? "evidence of {$id}",
        'fix_id' => $fixId,
        'disruptive' => false,
    ], fn ($v) => $v !== null);
}

/**
 * Run an audit of $server and answer it with $checks; returns the settled audit.
 *
 * @param  list<array<string, mixed>>  $checks
 */
function security_audit(FakeAgentGateway $agents, Server $server, array $checks): Audit
{
    app(StartAudit::class)(app(ServerDirectory::class)->find($server->id), 'manual');
    $command = $agents->last('security.audit', $server->id);
    $agents->succeed($command['handle'], ['checks' => $checks, 'duration_ms' => 1200]);

    return Audit::query()->where('command_id', $command['handle']->id)->firstOrFail();
}

/**
 * Replace the Databases module's backup posture with fixed answers.
 *
 * @param  list<DatabaseBackupPosture>  $databases
 */
function security_backups(array $databases): void
{
    app()->instance(BackupPosture::class, new class($databases) implements BackupPosture
    {
        /** @param list<DatabaseBackupPosture> $databases */
        public function __construct(private readonly array $databases) {}

        public function forServer(string $serverId): array
        {
            return $this->databases;
        }
    });
}
