<?php

namespace Kiln\Servers\Application\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Data\CommandHandle;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Domain\Models\PhpVersion;
use Kiln\Servers\Infrastructure\CommandPayloads;

/**
 * Stores php.ini overrides (applied with runtime.php.configure) and FPM pool defaults
 * (used by the Sites module when it creates runtime.fpm.pool for each site).
 */
final class UpdatePhpSettings
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, string|int|bool>  $ini
     * @param  array{pm: string, max_children: int, start_servers: int, min_spare_servers: int, max_spare_servers: int, max_requests: int}  $fpm
     */
    public function __invoke(PhpVersion $php, array $ini, array $fpm): ?string
    {
        $iniChanged = $ini != $php->ini;
        $php->forceFill(['ini' => $ini, 'fpm' => $fpm])->save();
        $server = $php->server()->firstOrFail();
        $commandId = null;

        if ($iniChanged) {
            $commandId = $this->dispatch(
                $server->id,
                'runtime.php.configure',
                CommandPayloads::phpConfigure($php),
                300,
                "php.configure:{$server->id}:{$php->version}:".Str::ulid(),
            )->id;
        }

        $this->audit->record('server.php_settings_updated', 'server', $server->id, [
            'version' => $php->version,
            'ini' => array_keys($ini),
            'fpm' => $fpm,
        ], $server->organization_id);

        return $commandId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(string $serverId, string $type, array $payload, int $timeout, string $key): CommandHandle
    {
        try {
            return $this->agents->dispatch($serverId, $type, $payload, $timeout, $key);
        } catch (AgentUnavailable) {
            throw ValidationException::withMessages(['ini' => 'The server agent is not connected. Reinstall the agent first.']);
        }
    }
}
