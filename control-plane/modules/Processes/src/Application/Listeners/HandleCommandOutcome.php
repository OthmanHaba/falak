<?php

namespace Kiln\Processes\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Processes\Application\StatusPoller;
use Kiln\Processes\Domain\Enums\ApplyStatus;
use Kiln\Processes\Domain\Models\ServerState;
use Kiln\Processes\Events\SchedulesApplied;
use Kiln\Processes\Infrastructure\StateScheduleDirectory;

/**
 * Settles proc.apply / cron.apply / proc.status commands dispatched by Processes. Only the currently
 * tracked command counts; results of superseded applies are ignored.
 */
final class HandleCommandOutcome implements ShouldQueue
{
    public function __construct(private readonly StatusPoller $status) {}

    public function handleFinished(CommandFinished $event): void
    {
        match ($event->type) {
            'proc.apply' => $this->applied($event, 'proc'),
            'cron.apply' => $this->applied($event, 'cron'),
            'proc.status' => StatusPoller::isStatusCommand($event->idempotencyKey) ? $this->status->record($event->serverId, $event->result) : null,
            default => null,
        };
    }

    public function handleFailed(CommandFailed $event): void
    {
        if (! in_array($event->type, ['proc.apply', 'cron.apply'], true)) {
            return;
        }

        $prefix = $event->type === 'proc.apply' ? 'proc' : 'cron';
        $reason = $event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');

        ServerState::query()
            ->where('server_id', $event->serverId)
            ->where("{$prefix}_command_id", $event->commandId)
            ->update(["{$prefix}_status" => ApplyStatus::Failed, "{$prefix}_error" => Str::limit($reason, 990), 'updated_at' => now()]);
    }

    private function applied(CommandFinished $event, string $prefix): void
    {
        $state = ServerState::query()->where('server_id', $event->serverId)->where("{$prefix}_command_id", $event->commandId)->first();

        if ($state === null) {
            return;
        }

        if ($prefix === 'proc') {
            $state->forceFill([
                'proc_status' => ApplyStatus::Applied,
                'proc_error' => null,
                'proc_applied_at' => now(),
                'applied_programs' => array_keys($state->programs ?? []),
                // Removed programs can no longer crash.
                'crash_looping' => array_values(array_intersect($state->crash_looping ?? [], array_keys($state->programs ?? []))) ?: null,
            ])->save();

            return;
        }

        $state->forceFill([
            'cron_status' => ApplyStatus::Applied,
            'cron_error' => null,
            'cron_applied_at' => now(),
            'applied_jobs' => array_keys($state->jobs ?? []),
        ])->save();

        SchedulesApplied::dispatch($state->server_id, $state->organization_id, StateScheduleDirectory::jobsOf($state));
    }
}
