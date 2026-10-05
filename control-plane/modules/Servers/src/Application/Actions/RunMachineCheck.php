<?php

namespace Falak\Servers\Application\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Application\MachineChecks;
use Falak\Servers\Application\ServerStatusUpdater;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\MachineInspection;
use Falak\Servers\Domain\Models\Server;

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
            // A new provisioning run replaces any converge still in flight: its late outcome must not flip the status
            // (HandleCommandOutcome only settles the server's current provision_command_id).
            $attributes = ['provision_command_id' => null];

            if ($server->provisioned_at !== null) {
                // An already provisioned server keeps its status (deploys keep targeting it) until the plan is applied.
                $this->status->set($server, $server->status, 'Checking the machine before re-provisioning.', $attributes);
            } else {
                $this->status->set($server, ServerStatus::Provisioning, 'Checking the machine before provisioning.', $attributes);
            }
        }

        $this->audit->record('server.machine_check_started', 'server', $server->id, ['purpose' => $purpose, 'command_id' => $handle->id], $server->organization_id, $actorId);

        return $handle->id;
    }
}
