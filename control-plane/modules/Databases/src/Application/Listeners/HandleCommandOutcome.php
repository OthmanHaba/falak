<?php

namespace Falak\Databases\Application\Listeners;

use Falak\Databases\Application\Actions\ApplyDatabaseUser;
use Falak\Databases\Application\Jobs\PruneScheduleBackups;
use Falak\Databases\Application\KeyValue\ApplyKeyValueInstance;
use Falak\Databases\Application\KeyValue\KeyValuePorts;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Grant;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Events\BackupFailed;
use Falak\Databases\Events\BackupSucceeded;
use Falak\Databases\Events\DatabaseCreated;
use Falak\Databases\Events\DatabaseDeleted;
use Falak\Databases\Events\RestoreFinished;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settles databases, users, backups and restores when the db.* commands Databases dispatched finish.
 */
final class HandleCommandOutcome implements ShouldQueue
{
    private const TYPES = ['db.create', 'db.drop', 'db.user.apply', 'db.backup', 'db.restore', 'db.redis.apply', 'db.redis.remove'];

    /** A new instance whose port turned out taken gets another one this many times before it fails. */
    private const PORT_RETRIES = 3;

    public function __construct(
        private readonly ApplyDatabaseUser $applyUser,
        private readonly AuditLog $audit,
        private readonly KeyValuePorts $ports,
        private readonly ApplyKeyValueInstance $applyInstance,
    ) {}

    public function handleFinished(CommandFinished $event): void
    {
        if (in_array($event->type, self::TYPES, true)) {
            $this->settle($event->type, $event->commandId, true, null, $event->result);
        }
    }

    public function handleFailed(CommandFailed $event): void
    {
        if (in_array($event->type, self::TYPES, true)) {
            $reason = $event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');
            $this->settle($event->type, $event->commandId, false, mb_substr($reason, 0, 1000), $event->result);
        }
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function settle(string $type, string $commandId, bool $succeeded, ?string $error, ?array $result): void
    {
        match ($type) {
            'db.create', 'db.drop', 'db.redis.remove' => $this->database($commandId, $succeeded, $error),
            'db.redis.apply' => $this->instance($commandId, $succeeded, $error, $result ?? []),
            'db.user.apply' => $this->user($commandId, $succeeded, $error),
            'db.backup' => $this->backup($commandId, $succeeded, $error, $result ?? []),
            'db.restore' => $this->restore($commandId, $succeeded, $error, $result ?? []),
        };
    }

    private function database(string $commandId, bool $succeeded, ?string $error): void
    {
        $database = Database::query()->with('databaseServer')->where('command_id', $commandId)->first();

        if (! $database) {
            return;
        }

        if ($database->status === ResourceStatus::Deleting) {
            if (! $succeeded) {
                $database->forceFill(['status' => ResourceStatus::Active, 'status_message' => "Drop failed: {$error}"])->save();

                return;
            }

            $userIds = $database->grants()->pluck('user_id')->all();
            $keyValue = $database->databaseServer->engine->isKeyValue();
            $database->delete();
            $this->audit->record('databases.database_deleted', 'database', $database->id, ['name' => $database->name, 'server_id' => $database->server_id], $database->organization_id);
            DatabaseDeleted::dispatch($database->id, $database->organization_id, $database->server_id, $database->name, $database->site_id);

            // An instance's `default` user existed for it only; SQL users lose the grant.
            $keyValue
                ? DatabaseUser::query()->whereIn('id', $userIds)->get()->each->delete()
                : DatabaseUser::query()->whereIn('id', $userIds)->where('status', '!=', ResourceStatus::Deleting)->get()
                    ->each(fn (DatabaseUser $user) => ($this->applyUser)($user, background: true));

            return;
        }

        $database->forceFill([
            'status' => $succeeded ? ResourceStatus::Active : ResourceStatus::Failed,
            'status_message' => $succeeded ? null : $error,
        ])->save();

        if (! $succeeded) {
            return;
        }

        DatabaseCreated::dispatch($database->id, $database->organization_id, $database->server_id, $database->name, $database->databaseServer->engine->value, $database->site_id);

        // Grants on this database could not be applied before it existed.
        Grant::query()->where('database_id', $database->id)->with('user')->get()
            ->map(fn (Grant $grant) => $grant->user)
            ->filter(fn (?DatabaseUser $user) => $user !== null && $user->status !== ResourceStatus::Deleting)
            ->each(fn (DatabaseUser $user) => ($this->applyUser)($user, background: true));
    }

    /**
     * db.redis.apply settles the instance (pending → active + DatabaseCreated, or failed) and its `default` user
     * (a password rotation). A failed re-apply of an active instance keeps it active with the reason.
     */
    private function instance(string $commandId, bool $succeeded, ?string $error, array $result = []): void
    {
        $this->user($commandId, $succeeded, $error);

        $database = Database::query()->with('databaseServer')->where('command_id', $commandId)->first();

        if (! $database || $database->status === ResourceStatus::Deleting) {
            return;
        }

        // What the instance listens on (db.redis.network agents report it): container_host is what containers on the
        // server connect to, bind what references from other servers need.
        if ($succeeded && is_array($result['bind'] ?? null)) {
            $database->forceFill(['network' => [
                ...(array) $database->network,
                'bind' => array_values(array_map('strval', $result['bind'])),
                'container_host' => is_string($result['container_host'] ?? null) && $result['container_host'] !== '' ? $result['container_host'] : null,
                'skipped' => array_values(array_map('strval', (array) ($result['skipped'] ?? []))),
                // The apply this answers (ConvergeKeyValueNetwork: one is in flight while the instance's command differs).
                'applied_command' => $commandId,
            ]])->save();
        }

        if ($database->status === ResourceStatus::Active) {
            $database->forceFill(['status' => ResourceStatus::Active, 'status_message' => $succeeded ? null : "Apply failed: {$error}"])->save();

            return;
        }

        if (! $succeeded && $this->movePort($database, (string) $error)) {
            return;
        }

        $database->forceFill([
            'status' => $succeeded ? ResourceStatus::Active : ResourceStatus::Failed,
            'status_message' => $succeeded ? null : $error,
        ])->save();

        if ($succeeded) {
            DatabaseCreated::dispatch($database->id, $database->organization_id, $database->server_id, $database->name, $database->databaseServer->engine->value, $database->site_id);
        }
    }

    /**
     * The agent found the new instance's port taken (something the machine check did not see): pick another port and
     * apply again, a few times.
     */
    private function movePort(Database $database, string $error): bool
    {
        if ($database->status !== ResourceStatus::Pending || preg_match('/port (\d+) is in use/', $error, $m) !== 1) {
            return false;
        }

        $avoid = array_values(array_unique([...array_map('intval', (array) ($database->settings['avoid_ports'] ?? [])), (int) $m[1]]));

        if (count($avoid) > self::PORT_RETRIES) {
            return false;
        }

        try {
            DB::transaction(function () use ($database, $avoid, $error) {
                DatabaseServer::query()->where('server_id', $database->server_id)->lockForUpdate()->get();
                $database->forceFill([
                    'port' => $this->ports->allocate($database->server_id, $avoid),
                    'settings' => [...(array) $database->settings, 'avoid_ports' => $avoid],
                    'status_message' => $error,
                ])->save();
            });
        } catch (ValidationException) {
            return false;
        }

        ($this->applyInstance)($database, background: true);

        return true;
    }

    private function user(string $commandId, bool $succeeded, ?string $error): void
    {
        $user = DatabaseUser::query()->where('command_id', $commandId)->first();

        if (! $user) {
            return;
        }

        if ($user->status === ResourceStatus::Deleting) {
            if ($succeeded) {
                $user->delete();
                $this->audit->record('databases.user_deleted', 'database_user', $user->id, ['username' => $user->username, 'server_id' => $user->server_id], $user->organization_id);
            } else {
                $user->forceFill(['status' => ResourceStatus::Failed, 'status_message' => "Delete failed: {$error}"])->save();
            }

            return;
        }

        $user->forceFill([
            'status' => $succeeded ? ResourceStatus::Active : ResourceStatus::Failed,
            'status_message' => $succeeded ? null : $error,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $result  db.backup $defs/result: size_bytes, sha256, location, duration_ms
     */
    private function backup(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $backup = Backup::query()->where('command_id', $commandId)->first();

        // A late success may overturn a control-plane timeout; anything else is settled once.
        if (! $backup || $backup->status === BackupStatus::Succeeded || $backup->status === BackupStatus::Pruned
            || ($backup->status === BackupStatus::Failed && ! $succeeded)) {
            return;
        }

        $sha = is_string($result['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $result['sha256']) === 1 ? $result['sha256'] : null;

        if ($succeeded && $sha === null) {
            [$succeeded, $error] = [false, 'The agent reported no checksum for the upload.'];
        }

        $finishedAt = now();
        $durationMs = isset($result['duration_ms']) ? (int) $result['duration_ms'] : (int) $backup->created_at->diffInMilliseconds($finishedAt);

        if (! $succeeded) {
            $backup->forceFill(['status' => BackupStatus::Failed, 'error' => $error, 'finished_at' => $finishedAt, 'duration_ms' => $durationMs])->save();
            BackupFailed::dispatch($backup->id, $backup->organization_id, $backup->server_id, $backup->server_name, $backup->database_name, (string) $error, $backup->schedule_id, $backup->trigger);

            return;
        }

        $backup->forceFill([
            'status' => BackupStatus::Succeeded,
            'size_bytes' => (int) ($result['size_bytes'] ?? 0),
            'sha256' => $sha,
            'duration_ms' => $durationMs,
            'error' => null,
            'finished_at' => $finishedAt,
        ])->save();

        BackupSucceeded::dispatch($backup->id, $backup->organization_id, $backup->server_id, $backup->server_name, $backup->database_name, (int) $backup->size_bytes, (string) $sha, $durationMs, $backup->schedule_id, $backup->trigger);

        if ($backup->schedule_id !== null) {
            PruneScheduleBackups::dispatch($backup->schedule_id);
        }
    }

    /**
     * @param  array<string, mixed>  $result  db.restore $defs/result: bytes, duration_ms
     */
    private function restore(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $restore = Restore::query()->where('command_id', $commandId)->first();

        if (! $restore || $restore->status === RestoreStatus::Succeeded || ($restore->status === RestoreStatus::Failed && ! $succeeded)) {
            return;
        }

        $restore->forceFill([
            'status' => $succeeded ? RestoreStatus::Succeeded : RestoreStatus::Failed,
            'bytes' => isset($result['bytes']) ? (int) $result['bytes'] : null,
            'duration_ms' => isset($result['duration_ms']) ? (int) $result['duration_ms'] : null,
            'error' => $succeeded ? null : $error,
            'finished_at' => now(),
        ])->save();

        $keyValue = DatabaseServer::query()->find($restore->database_server_id)?->engine->isKeyValue() ?? false;

        if ($succeeded && ! $keyValue) {
            // The agent creates the database when missing; track it like any other (instances are never created).
            $database = Database::query()->firstOrCreate(
                ['database_server_id' => $restore->database_server_id, 'name' => $restore->database_name],
                ['organization_id' => $restore->organization_id, 'server_id' => $restore->server_id, 'status' => ResourceStatus::Active, 'created_by' => $restore->requested_by],
            );

            if ($database->status === ResourceStatus::Failed) {
                $database->forceFill(['status' => ResourceStatus::Active, 'status_message' => null])->save();
            }
        }

        $this->audit->record($succeeded ? 'databases.restore_succeeded' : 'databases.restore_failed', 'backup', $restore->backup_id, ['restore_id' => $restore->id, 'database' => $restore->database_name], $restore->organization_id);

        RestoreFinished::dispatch($restore->id, $restore->organization_id, $restore->backup_id, $restore->server_id, $restore->database_name, $succeeded, $succeeded ? null : $error);
    }
}
