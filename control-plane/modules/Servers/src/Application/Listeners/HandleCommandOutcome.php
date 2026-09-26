<?php

namespace Kiln\Servers\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Application\Actions\SyncServerSshKeys;
use Kiln\Servers\Application\ServerStatusUpdater;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Enums\PhpVersionStatus;
use Kiln\Servers\Domain\Models\PhpVersion;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\PhpVersionChanged;
use Kiln\Servers\Events\ServerProvisioned;

/**
 * Reacts to the outcome of commands Servers dispatched: provisioning plans and PHP version changes.
 */
final class HandleCommandOutcome implements ShouldQueue
{
    public function __construct(
        private readonly ServerStatusUpdater $status,
        private readonly SyncServerSshKeys $syncKeys,
        private readonly AuditLog $audit,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        $server = Server::query()->find($event->serverId);

        if (! $server || $server->organization_id !== $event->organizationId) {
            return;
        }

        if ($event->commandId === $server->provision_command_id) {
            $this->provisioned($server);
        }

        $this->settlePhpVersions($server, $event->commandId, succeeded: true, error: null);
    }

    public function handleFailed(CommandFailed $event): void
    {
        $server = Server::query()->find($event->serverId);

        if (! $server || $server->organization_id !== $event->organizationId) {
            return;
        }

        $reason = $event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');

        if ($event->commandId === $server->provision_command_id && $server->status === ServerStatus::Provisioning) {
            $this->status->set($server, ServerStatus::Error, "Provisioning failed: {$reason}");
            $this->audit->record('server.provisioning_failed', 'server', $server->id, ['command_id' => $event->commandId, 'status' => $event->status], $server->organization_id);
        }

        $this->settlePhpVersions($server, $event->commandId, succeeded: false, error: $reason);
    }

    private function provisioned(Server $server): void
    {
        // Error covers a control-plane timeout that the agent later overturned with a successful result.
        $wasProvisioning = in_array($server->status, [ServerStatus::Provisioning, ServerStatus::Error], true);

        // Versions that the plan installed / removed.
        $server->phpVersions()->where('status', PhpVersionStatus::Installing)->where(fn ($q) => $q->whereNull('command_id')->orWhere('command_id', $server->provision_command_id))
            ->update(['status' => PhpVersionStatus::Installed, 'status_message' => null]);

        if (! $wasProvisioning) {
            return;
        }

        $this->status->set($server, ServerStatus::Active, null, ['provisioned_at' => $server->provisioned_at ?? now()]);
        $this->audit->record('server.provisioned', 'server', $server->id, ['attempt' => $server->provision_attempts], $server->organization_id);

        ServerProvisioned::dispatch($server->id, $server->organization_id, $server->type->value, $server->name);

        ($this->syncKeys)($server->refresh());
    }

    private function settlePhpVersions(Server $server, string $commandId, bool $succeeded, ?string $error): void
    {
        $server->phpVersions()->where('command_id', $commandId)->get()->each(function (PhpVersion $php) use ($server, $succeeded, $error) {
            if ($php->status === PhpVersionStatus::Removing) {
                if ($succeeded) {
                    $php->delete();
                    PhpVersionChanged::dispatch($server->id, $server->organization_id, $php->version, 'removed');
                } else {
                    $php->forceFill(['status' => PhpVersionStatus::Installed, 'status_message' => "Removal failed: {$error}"])->save();
                }

                return;
            }

            // Failed + success = a late result after a control-plane timeout.
            if ($php->status === PhpVersionStatus::Installing || ($succeeded && $php->status === PhpVersionStatus::Failed)) {
                $php->forceFill([
                    'status' => $succeeded ? PhpVersionStatus::Installed : PhpVersionStatus::Failed,
                    'status_message' => $succeeded ? null : $error,
                ])->save();

                if ($succeeded) {
                    PhpVersionChanged::dispatch($server->id, $server->organization_id, $php->version, 'installed');
                }
            }
        });
    }
}
