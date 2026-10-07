<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\Passwords;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Restart, delete and password rotation of a database container. Each dispatches one db.instance.* command; the
 * instance settles when it finishes (HandleCommandOutcome).
 */
final class InstanceLifecycle
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function restart(DatabaseInstance $instance, ?string $actorId = null): void
    {
        $this->assertRunning($instance);

        $handle = $this->commands->dispatch($instance->server_id, 'db.instance.restart', ['id' => $instance->id], (int) config('databases.timeouts.instance', 1800), "db.instance.restart:{$instance->id}:".Str::ulid(), 'instance');
        $instance->forceFill(['command_id' => $handle->id, 'status_message' => null])->save();

        $this->audit->record('databases.instance_restarted', 'database_instance', $instance->id, ['name' => $instance->name], $instance->organization_id);
    }

    /**
     * Remove the container. Its data volume stays (unattached, in Volumes) unless $deleteVolume. The rows go once the
     * agent confirms.
     *
     * @throws ValidationException
     */
    public function delete(DatabaseInstance $instance, bool $deleteVolume = false, ?string $actorId = null, bool $background = false): void
    {
        if ($instance->status === InstanceStatus::Upgrading) {
            throw ValidationException::withMessages(['instance' => 'A major upgrade is running: wait for it to finish.']);
        }

        $key = "db.instance.delete:{$instance->id}:".Str::ulid();
        $handle = $background
            ? $this->commands->tryDispatch($instance->server_id, 'db.instance.delete', ['id' => $instance->id], (int) config('databases.timeouts.ddl', 300), $key)
            : $this->commands->dispatch($instance->server_id, 'db.instance.delete', ['id' => $instance->id], (int) config('databases.timeouts.ddl', 300), $key, 'instance');

        if ($handle === null) {
            $instance->forceFill(['status_message' => 'Not deleted: '.AgentCommands::NOT_CONNECTED])->save();

            return;
        }

        DB::transaction(function () use ($instance, $deleteVolume, $handle) {
            $instance->forceFill(['status' => InstanceStatus::Deleting, 'delete_volume' => $deleteVolume, 'command_id' => $handle->id, 'status_message' => null])->save();
            $instance->databases()->update(['status' => ResourceStatus::Deleting]);
        });

        $this->audit->record('databases.instance_delete_requested', 'database_instance', $instance->id, ['name' => $instance->name, 'server_id' => $instance->server_id, 'delete_volume' => $deleteVolume], $instance->organization_id);
    }

    /**
     * New superuser (Redis / Valkey: `default`) password. It replaces the stored one once the agent has set it in the
     * engine and in the container's secret file, so references never carry a password the engine does not know yet.
     *
     * @throws ValidationException
     */
    public function rotatePassword(DatabaseInstance $instance, #[\SensitiveParameter] ?string $password = null, ?string $actorId = null): void
    {
        $this->assertRunning($instance);

        if ($password !== null && $password !== '' && preg_match('/^[A-Za-z0-9._~-]{12,128}$/', $password) !== 1) {
            // Passwords go into URLs (DATABASE_URL, REDIS_URL) unquoted.
            throw ValidationException::withMessages(['password' => 'Use 12–128 letters, digits, dots, dashes, underscores or tildes.']);
        }

        $next = $password ?: Passwords::generate();

        $handle = $this->commands->dispatch(
            $instance->server_id,
            'db.instance.password',
            ['id' => $instance->id, 'engine' => $instance->engine->protocol(), 'password' => $next],
            (int) config('databases.timeouts.ddl', 300),
            "db.instance.password:{$instance->id}:".Str::ulid(),
            'password',
        );

        $instance->forceFill(['next_root_password' => $next, 'command_id' => $handle->id, 'status_message' => null])->save();

        $this->audit->record('databases.instance_password_rotated', 'database_instance', $instance->id, ['name' => $instance->name, 'generated' => $password === null || $password === ''], $instance->organization_id);
    }

    /**
     * @throws ValidationException
     */
    private function assertRunning(DatabaseInstance $instance): void
    {
        if (! $instance->isRunning()) {
            throw ValidationException::withMessages(['instance' => "The database is {$instance->status->value}."]);
        }
    }
}
