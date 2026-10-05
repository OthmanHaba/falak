<?php

namespace Falak\Servers\Application\Actions;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Events\PhpVersionChanged;
use Falak\Servers\Infrastructure\CommandPayloads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SetDefaultPhpVersion
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(PhpVersion $php): void
    {
        if ($php->status !== PhpVersionStatus::Installed) {
            throw ValidationException::withMessages(['version' => 'Only installed versions can become the default.']);
        }

        $server = $php->server()->firstOrFail();

        $this->dispatch(
            $server->id,
            'runtime.php.install',
            CommandPayloads::phpInstall($server, $php->version, (array) config('servers.php_extensions'), true),
            1200,
            "php.default:{$server->id}:{$php->version}:".Str::ulid(),
        );

        DB::transaction(function () use ($server, $php) {
            $server->phpVersions()->update(['is_default' => false]);
            $php->forceFill(['is_default' => true])->save();
            $server->forceFill(['stack' => $server->stack->withPhp((string) $server->stack->phpRuntime, $server->desiredPhpVersions(), $php->version)])->save();
        });

        $this->audit->record('server.php_default_changed', 'server', $server->id, ['version' => $php->version], $server->organization_id);
        PhpVersionChanged::dispatch($server->id, $server->organization_id, $php->version, 'default');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(string $serverId, string $type, array $payload, int $timeout, string $key): CommandHandle
    {
        try {
            return $this->agents->dispatch($serverId, $type, $payload, $timeout, $key);
        } catch (AgentUnavailable) {
            throw ValidationException::withMessages(['version' => 'The server agent is not connected. Reinstall the agent first.']);
        }
    }
}
