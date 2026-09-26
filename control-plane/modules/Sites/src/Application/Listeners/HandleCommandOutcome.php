<?php

namespace Kiln\Sites\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Sites\Application\TargetProvisioner;
use Kiln\Sites\Domain\Models\SiteCommand;
use Kiln\Sites\Domain\Models\SiteTarget;

/**
 * Advances target preparation and records the outcome of site commands.
 */
final class HandleCommandOutcome implements ShouldQueue
{
    private const TYPES = ['system.user.create', 'runtime.fpm.pool', 'runtime.bun.install', 'runtime.deno.install', 'system.exec'];

    public function __construct(private readonly TargetProvisioner $provisioner) {}

    public function handleFinished(CommandFinished $event): void
    {
        if (! in_array($event->type, self::TYPES, true)) {
            return;
        }

        $this->settleCommand($event->commandId, CommandStatus::Succeeded->value, $event->exitCode);

        $target = $this->target($event->commandId, $event->serverId);

        if ($target) {
            $this->provisioner->advance($target);
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
        if (! in_array($event->type, self::TYPES, true)) {
            return;
        }

        $this->settleCommand($event->commandId, $event->status, $event->exitCode);

        $target = $this->target($event->commandId, $event->serverId);

        if ($target) {
            $step = match ($target->step) {
                SiteTarget::STEP_USER => 'Creating the site user',
                SiteTarget::STEP_RUNTIME => 'Installing the '.ucfirst($target->site->runtime->value).' runtime',
                default => 'Configuring the PHP-FPM pool',
            };
            $reason = $event->error ?: "command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');
            $this->provisioner->fail($target, "{$step} failed: {$reason}");
        }
    }

    private function target(string $commandId, string $serverId): ?SiteTarget
    {
        return SiteTarget::query()->with('site')->where('command_id', $commandId)->where('server_id', $serverId)->first();
    }

    private function settleCommand(string $commandId, string $status, ?int $exitCode): void
    {
        SiteCommand::query()->where('command_id', $commandId)->update([
            'status' => $status,
            'exit_code' => $exitCode,
            'finished_at' => now(),
        ]);
    }
}
