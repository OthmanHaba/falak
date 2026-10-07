<?php

namespace Falak\Databases\Application\Jobs;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Instances a major upgrade replaced are kept stopped for databases.retire_hours, then removed with their volume
 * (db.instance.delete; HandleCommandOutcome deletes the volume and the row). Retried every run until the agent answers.
 */
final class RemoveRetiredInstances implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(AgentCommands $commands): void
    {
        $due = DatabaseInstance::query()->where('status', InstanceStatus::Retired)->where('retire_at', '<=', now())->limit(50)->get();

        foreach ($due as $instance) {
            $handle = $commands->tryDispatch($instance->server_id, 'db.instance.delete', ['id' => $instance->id], (int) config('databases.timeouts.ddl', 300), "db.instance.delete:{$instance->id}:".Str::ulid());

            if ($handle !== null) {
                // Not again before the agent had time to answer.
                $instance->forceFill(['command_id' => $handle->id, 'retire_at' => now()->addHour()])->save();
            }
        }
    }
}
