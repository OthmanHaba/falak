<?php

namespace Kiln\Servers\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Application\MachineChecks;
use Kiln\Servers\Application\PhpVersionsForOs;
use Kiln\Servers\Application\ServerStatusUpdater;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Infrastructure\ProvisioningPlanBuilder;

/**
 * Sends the full provisioning plan (provision.apply) to the server's agent. Used after the machine check (or right
 * after enrollment for agents without it), to retry a failed provisioning and to converge after changes (e.g. PHP
 * version removal). The plan follows the latest machine check's decisions, recomputed for the current stack.
 */
final class ApplyProvisioningPlan
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ProvisioningPlanBuilder $plans,
        private readonly ServerStatusUpdater $status,
        private readonly AuditLog $audit,
        private readonly PhpVersionsForOs $php,
        private readonly MachineChecks $checks,
    ) {}

    public function __invoke(Server $server, bool $markProvisioning = true): string
    {
        $attempt = $server->provision_attempts + 1;
        $phpNote = $this->php->fit($server);

        try {
            $handle = $this->agents->dispatch(
                $server->id,
                'provision.apply',
                $this->plans->build($server, $this->checks->current($server)),
                (int) config('servers.provision_timeout', 1800),
                "provision:{$server->id}:{$attempt}",
            );
        } catch (AgentUnavailable) {
            if (! $markProvisioning) {
                throw ValidationException::withMessages(['server' => 'The server agent is not connected. Reinstall the agent first.']);
            }

            $this->status->set($server, ServerStatus::Error, 'The server agent is not enrolled. Run the install command on the server.');

            return '';
        }

        $attributes = ['provision_command_id' => $handle->id, 'provision_attempts' => $attempt];

        if ($markProvisioning) {
            $this->status->set($server, ServerStatus::Provisioning, trim('Applying provisioning plan. '.$phpNote), $attributes);
        } else {
            $server->forceFill($attributes)->save();
        }

        $this->audit->record('server.provisioning_started', 'server', $server->id, ['attempt' => $attempt, 'command_id' => $handle->id], $server->organization_id);

        return $handle->id;
    }
}
