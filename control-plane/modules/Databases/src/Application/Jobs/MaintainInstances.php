<?php

namespace Falak\Databases\Application\Jobs;

use Falak\Databases\Application\Actions\ApplyInstance;
use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\InstanceCertificates;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Housekeeping of database containers, every ten minutes:
 *
 * - a major upgrade's retired instance loses its container (never its data volume, which waits for someone to delete
 *   it after verifying the new one) once retire_at passed and the instance that replaced it is healthy;
 * - certificates expiring within databases.tls_renew_days, or no longer covering the instance's names and addresses,
 *   are issued again (the engine restarts with them);
 * - a Redis / Valkey rotation's previous password is retired once its overlap ended.
 */
final class MaintainInstances implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(AgentCommands $commands, ApplyInstance $apply, InstanceCertificates $certificates): void
    {
        $retired = DatabaseInstance::query()->where('status', InstanceStatus::Retired)->whereNotNull('retire_at')->where('retire_at', '<=', now())->limit(50)->get();

        foreach ($retired as $instance) {
            $replacement = $instance->replaced_by !== null ? DatabaseInstance::query()->find($instance->replaced_by) : null;

            if ($replacement?->status !== InstanceStatus::Active || $replacement->health !== 'healthy') {
                continue;
            }

            $handle = $commands->tryDispatch($instance->server_id, 'db.instance.delete', ['id' => $instance->id], (int) config('databases.timeouts.ddl', 300), "db.instance.delete:{$instance->id}:".Str::ulid());

            if ($handle !== null) {
                // Not again before the agent had time to answer.
                $instance->forceFill(['command_id' => $handle->id, 'retire_at' => now()->addHour()])->save();
            }
        }

        foreach (DatabaseInstance::query()->where('status', InstanceStatus::Active)->orderBy('tls_expires_at')->limit(500)->get() as $instance) {
            if ($certificates->due($instance)) {
                $apply($instance, background: true, renewCertificate: true);
            }
        }

        $overlaps = DatabaseInstance::query()->where('status', InstanceStatus::Active)->whereNotNull('password_overlap_until')->where('password_overlap_until', '<=', now())->limit(50)->get();

        foreach ($overlaps as $instance) {
            $handle = $commands->tryDispatch($instance->server_id, 'db.instance.password', ['id' => $instance->id, 'engine' => $instance->engine->protocol(), 'mode' => 'retire'], (int) config('databases.timeouts.ddl', 300), "db.instance.password:{$instance->id}:".Str::ulid());

            if ($handle !== null) {
                $instance->forceFill(['password_overlap_until' => now()->addHour()])->save();
            }
        }
    }
}
