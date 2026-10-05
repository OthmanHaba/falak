<?php

namespace Falak\Servers\Application\Actions;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Application\MachineChecks;
use Falak\Servers\Application\ServerStatusUpdater;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\MachineCheck\ComponentDecision;
use Falak\Servers\Domain\MachineCheck\Note;
use Falak\Servers\Domain\Models\MachineInspection;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Events\ServerAttentionCleared;
use Falak\Servers\Events\ServerNeedsAttention;
use Falak\Servers\Events\ServerUpdated;

/**
 * Records the outcome of provision.inspect: the report and the decisions taken from it. A provisioning check then
 * applies the plan, or stops at needs_attention when something blocks (a server provisioned before keeps its status).
 */
final class RecordMachineCheck
{
    public function __construct(
        private readonly MachineChecks $checks,
        private readonly ApplyProvisioningPlan $apply,
        private readonly ServerStatusUpdater $status,
        private readonly AgentDirectory $agents,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $report  the command result
     */
    public function finished(Server $server, MachineInspection $inspection, ?array $report): void
    {
        if ($report === null || ! is_array($report['packages'] ?? null)) {
            $this->failed($server, $inspection, 'The agent sent no machine report.');

            return;
        }

        $check = $this->checks->decide($server, $report);
        $wasBlocking = $inspection->blocking;

        $inspection->forceFill([
            'status' => MachineInspection::FINISHED,
            'report' => $report,
            'decisions' => $check->toArray(),
            'blocking' => $check->blocking(),
            'agent_version' => $this->agents->forServer($server->id)?->version,
            'error' => null,
            'checked_at' => now(),
        ])->save();

        $this->audit->record('server.machine_checked', 'server', $server->id, [
            'purpose' => $inspection->purpose,
            'blocking' => $check->blocking(),
            'decisions' => collect($check->components)->mapWithKeys(fn ($d) => [$d->component => $d->decision->value])->all(),
        ], $server->organization_id);

        if ($check->blocking()) {
            ServerNeedsAttention::dispatch($server->id, $server->organization_id, $server->name, $this->blockMessages($check->blocked()));
        } elseif ($wasBlocking || $server->status === ServerStatus::NeedsAttention) {
            ServerAttentionCleared::dispatch($server->id, $server->organization_id, $server->name);
        }

        if ($inspection->purpose === MachineInspection::PURPOSE_PROVISION && $this->reprovisioning($server)) {
            // A provisioned server never goes to needs_attention: it keeps serving with its current state; the blocks
            // are reported (panel, status message, alert) and nothing is applied.
            if ($check->blocking()) {
                $this->status->set($server, $server->status, 'Re-provisioning stopped. '.$check->summary());

                return;
            }

            ($this->apply)($server);

            return;
        }

        if ($inspection->purpose === MachineInspection::PURPOSE_PROVISION && $server->status === ServerStatus::Provisioning) {
            if (! $check->blocking()) {
                ($this->apply)($server);

                return;
            }

            $this->status->set($server, ServerStatus::NeedsAttention, $check->summary());

            return;
        }

        if ($server->status === ServerStatus::NeedsAttention) {
            $this->status->set($server, ServerStatus::NeedsAttention, $check->blocking() ? $check->summary() : 'Nothing blocks provisioning any more. Provision to continue.');

            return;
        }

        // A re-check on any other server only refreshes the panel.
        ServerUpdated::dispatch($server->id, $server->status->value, $server->status_message, $server->provision_command_id);
    }

    public function failed(Server $server, MachineInspection $inspection, string $reason): void
    {
        $inspection->forceFill(['status' => MachineInspection::FAILED, 'error' => mb_substr($reason, 0, 1000)])->save();

        if ($inspection->purpose === MachineInspection::PURPOSE_PROVISION && $this->reprovisioning($server)) {
            $this->status->set($server, $server->status, "Re-provisioning stopped: the machine check failed: {$reason}");

            return;
        }

        if ($inspection->purpose === MachineInspection::PURPOSE_PROVISION && $server->status === ServerStatus::Provisioning) {
            $this->status->set($server, ServerStatus::Error, "Machine check failed: {$reason}");
            $this->audit->record('server.provisioning_failed', 'server', $server->id, ['command_id' => $inspection->command_id, 'stage' => 'machine_check'], $server->organization_id);

            return;
        }

        ServerUpdated::dispatch($server->id, $server->status->value, $server->status_message, $server->provision_command_id);
    }

    /**
     * A Re-provision of a server that was provisioned before (RunMachineCheck kept its status).
     */
    private function reprovisioning(Server $server): bool
    {
        return $server->provisioned_at !== null && in_array($server->status, [ServerStatus::Active, ServerStatus::Error], true);
    }

    /**
     * @param  list<ComponentDecision>  $blocked
     * @return list<string>
     */
    private function blockMessages(array $blocked): array
    {
        $messages = [];

        foreach ($blocked as $decision) {
            $messages = [...$messages, ...array_map(fn (Note $note) => $note->message, $decision->blocks())];
        }

        return $messages;
    }
}
