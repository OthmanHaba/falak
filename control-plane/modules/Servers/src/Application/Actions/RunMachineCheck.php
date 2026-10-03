<?php

namespace Kiln\Servers\Application\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Application\MachineChecks;
use Kiln\Servers\Application\ServerStatusUpdater;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\MachineInspection;
use Kiln\Servers\Domain\Models\Server;

/**
 * Sends provision.inspect to the server's agent (read-only). The result is recorded by {@see RecordMachineCheck};
 * with the provision purpose the plan follows when nothing blocks.
 */
final class RunMachineCheck
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly MachineChecks $checks,
        private readonly ServerStatusUpdater $status,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  string  $purpose  MachineInspection::PURPOSE_PROVISION (apply when nothing blocks) or PURPOSE_CHECK (report only)
     * @return string the provision.inspect command id
     */
    public function __invoke(Server $server, string $purpose = MachineInspection::PURPOSE_CHECK, ?string $actorId = null): string
    {
        if (! $this->checks->supported($server)) {
            throw ValidationException::withMessages(['server' => 'The agent on this server cannot run the machine check. Update the agent first.']);
        }

        try {
            $handle = $this->agents->dispatch(
                $server->id,
                'provision.inspect',
                ['packages' => array_values(array_map('strval', (array) config('servers.base_packages', [])))],
                (int) config('servers.machine_check.timeout', 180),
                "inspect:{$server->id}:".Str::ulid(),
            );
        } catch (AgentUnavailable) {
            if ($purpose === MachineInspection::PURPOSE_PROVISION) {
                $this->status->set($server, ServerStatus::Error, 'The server agent is not enrolled. Run the install command on the server.');

                return '';
            }

            throw ValidationException::withMessages(['server' => 'The server agent is not connected. Reinstall the agent first.']);
        }

        MachineInspection::query()->updateOrCreate(['server_id' => $server->id], [
            'command_id' => $handle->id,
            'purpose' => $purpose,
            'status' => MachineInspection::RUNNING,
            'error' => null,
        ]);

        if ($purpose === MachineInspection::PURPOSE_PROVISION) {
            $this->status->set($server, ServerStatus::Provisioning, 'Checking the machine before provisioning.');
        }

        $this->audit->record('server.machine_check_started', 'server', $server->id, ['purpose' => $purpose, 'command_id' => $handle->id], $server->organization_id, $actorId);

        return $handle->id;
    }
}
