<?php

namespace Kiln\Servers\Application\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Data\CommandHandle;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Enums\PhpVersionStatus;
use Kiln\Servers\Domain\Models\PhpVersion;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Infrastructure\CommandPayloads;

final class InstallPhpVersion
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Server $server, string $version): PhpVersion
    {
        if (! $server->type->hostsSites() || $server->stack->phpRuntime === null) {
            throw ValidationException::withMessages(['version' => 'This server does not run PHP.']);
        }

        if (! in_array($version, (array) config('servers.php_versions'), true)) {
            throw ValidationException::withMessages(['version' => 'Unsupported PHP version.']);
        }

        if ($server->status !== ServerStatus::Active) {
            throw ValidationException::withMessages(['version' => 'PHP versions can be managed once the server is active.']);
        }

        $existing = $server->phpVersions()->where('version', $version)->first();

        if ($existing && in_array($existing->status, [PhpVersionStatus::Installed, PhpVersionStatus::Installing], true)) {
            throw ValidationException::withMessages(['version' => "PHP {$version} is already installed."]);
        }

        $handle = $this->dispatch(
            $server->id,
            'runtime.php.install',
            CommandPayloads::phpInstall($server, $version, (array) config('servers.php_extensions'), false),
            1200,
            "php.install:{$server->id}:{$version}:".Str::ulid(),
        );

        $php = $existing ?? new PhpVersion(['server_id' => $server->id, 'version' => $version]);
        $php->forceFill([
            'status' => PhpVersionStatus::Installing,
            'command_id' => $handle->id,
            'status_message' => null,
            'is_default' => $php->is_default ?? false,
            'ini' => $php->ini ?? PhpVersion::DEFAULT_INI,
            'fpm' => $php->fpm ?? PhpVersion::defaultFpm($server->memory_bytes),
        ])->save();

        $this->audit->record('server.php_install_requested', 'server', $server->id, ['version' => $version], $server->organization_id);

        return $php;
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
