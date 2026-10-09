<?php

namespace Falak\Databases\Application\Actions;

use Carbon\CarbonImmutable;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Grant;
use Falak\Databases\Domain\Models\PitrGap;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Events\DatabaseCreated;
use Falak\Databases\Events\PitrAlert;
use Falak\Databases\Events\RestoreFinished;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settles the point-in-time recovery commands (HandleCommandOutcome):
 *
 *  - db.pitr.base: the base is recorded with where in the log it starts; gaps before it are resolved;
 *  - db.pitr.restore: the restore awaits a decision, the new instance is inspected read-only (a failed one is removed
 *    with its volume);
 *  - db.pitr.promote: the decision takes effect: swap (the new instance takes over the old one, which is kept stopped
 *    with its volume) or keep (a separate database, on the canvas next to the old one).
 */
final class SettlePitr
{
    public function __construct(
        private readonly TakePitrBase $base,
        private readonly TakeOverInstance $takeOver,
        private readonly InstanceLifecycle $lifecycle,
        private readonly ApplyInstance $apply,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $result  db.pitr.base $defs/result
     */
    public function base(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $backup = Backup::query()->where('command_id', $commandId)->where('type', Backup::BASE)->first();

        if ($backup === null || $backup->status === BackupStatus::Succeeded || $backup->status === BackupStatus::Pruned
            || ($backup->status === BackupStatus::Failed && ! $succeeded)) {
            return;
        }

        $instance = DatabaseInstance::query()->find($backup->database_instance_id);
        $sha = self::sha($result['sha256'] ?? null);
        $plainSha = self::sha($result['plaintext_sha256'] ?? null);
        $started = self::time($result['started_at'] ?? null);
        $finished = self::time($result['finished_at'] ?? null);

        if ($succeeded && ($sha === null || $plainSha === null || ($result['key_id'] ?? null) !== $backup->id || ($result['encryption'] ?? null) !== ($backup->isCustomerHeld() ? 'age' : 'cp'))) {
            [$succeeded, $error] = [false, 'The agent did not report the base backup as encrypted with its key.'];
        } elseif ($succeeded && ($started === null || $finished === null)) {
            [$succeeded, $error] = [false, 'The agent reported no start and end time for the base backup.'];
        }

        if (! $succeeded) {
            $backup->forceFill(['status' => BackupStatus::Failed, 'error' => $error, 'finished_at' => now()])->save();

            if ($instance !== null) {
                $instance->forceFill(['pitr_next_base_at' => now()->addMinutes((int) config('databases.pitr.base_retry_minutes', 60))])->save();
                PitrAlert::dispatch(PitrAlert::BASE_FAILED, $instance->organization_id, $instance->id, $instance->name, $instance->server_name, (string) $error);
            }

            return;
        }

        $logStart = $result['start_wal'] ?? $result['start_binlog'] ?? null;

        $backup->forceFill([
            'status' => BackupStatus::Succeeded,
            'size_bytes' => (int) ($result['size_bytes'] ?? 0),
            'uncompressed_bytes' => is_int($result['uncompressed_bytes'] ?? null) ? $result['uncompressed_bytes'] : null,
            'sha256' => $sha,
            'plaintext_sha256' => $plainSha,
            'log_start' => is_string($logStart) ? mb_substr($logStart, 0, 64) : null,
            'log_stop' => is_string($result['stop_wal'] ?? null) ? mb_substr($result['stop_wal'], 0, 64) : null,
            'base_started_at' => $started,
            'base_finished_at' => $finished,
            'duration_ms' => isset($result['duration_ms']) ? (int) $result['duration_ms'] : null,
            'error' => null,
            'finished_at' => now(),
        ])->save();

        if ($instance === null) {
            return;
        }

        // A gap before this base no longer matters: recovery starts again from it.
        $resolved = PitrGap::query()->where('database_instance_id', $instance->id)->whereNull('resolved_at')->update(['resolved_at' => now()]);

        foreach (array_filter([PitrAlert::BASE_FAILED, $resolved > 0 ? PitrAlert::GAP : null]) as $problem) {
            PitrAlert::dispatch(PitrAlert::RECOVERED, $instance->organization_id, $instance->id, $instance->name, $instance->server_name, '', $problem);
        }
    }

    /**
     * A new instance became active (created, or the target of a major upgrade that took over): with PITR on, its
     * recovery starts with a base of its own.
     */
    public function baseAfterTakeOver(DatabaseInstance $instance): void
    {
        ($this->base)($instance, 'enabled');
    }

    /**
     * @param  array<string, mixed>  $result  db.pitr.restore $defs/result
     */
    public function restored(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $restore = Restore::query()->where('command_id', $commandId)->where('type', Restore::PITR)->first();

        if ($restore === null || ! in_array($restore->status, [RestoreStatus::Pending, RestoreStatus::Running], true)) {
            return;
        }

        $copy = DatabaseInstance::query()->find($restore->restored_instance_id);
        $source = DatabaseInstance::query()->find($restore->source_instance_id);

        if (! $succeeded || $copy === null) {
            $restore->forceFill(['status' => RestoreStatus::Failed, 'error' => $error ?? 'The restored instance is gone.', 'finished_at' => now()])->save();

            if ($copy !== null) {
                // Nothing to decide about: the container (if any) and its volume go.
                $copy->forceFill(['status' => InstanceStatus::Failed, 'status_message' => "The point-in-time restore failed: {$error}"])->save();
                $this->discard($copy);
            }

            $this->audit->record('databases.pitr_restore_failed', 'database_instance', $restore->source_instance_id, ['restore_id' => $restore->id], $restore->organization_id);
            RestoreFinished::dispatch($restore->id, $restore->organization_id, (string) $restore->backup_id, $restore->server_id, (string) $source?->name, false, $error);

            return;
        }

        $digest = is_string($result['image_digest'] ?? null) && preg_match('/^sha256:[a-f0-9]{64}$/', $result['image_digest']) === 1 ? $result['image_digest'] : null;

        DB::transaction(function () use ($restore, $copy, $result, $digest) {
            $restore->forceFill([
                'status' => RestoreStatus::AwaitingDecision,
                'table_counts' => self::counts($result['table_counts'] ?? null),
                'bytes' => isset($result['downloaded_bytes']) ? (int) $result['downloaded_bytes'] : null,
                'duration_ms' => isset($result['duration_ms']) ? (int) $result['duration_ms'] : null,
                'warnings' => array_values(array_slice(array_filter((array) ($result['warnings'] ?? []), 'is_string'), 0, 10)) ?: null,
                'error' => null,
                'finished_at' => now(),
            ])->save();

            $copy->forceFill([
                'status' => InstanceStatus::Inspecting,
                'status_message' => 'Read-only copy at '.$restore->target_time?->toIso8601ZuluString().': swap it in, keep it or discard it.',
                'health' => is_string($result['health'] ?? null) ? $result['health'] : null,
                'health_at' => now(),
                ...($digest !== null ? ['image_digest' => $digest] : []),
            ])->save();
        });

        $this->audit->record('databases.pitr_restore_ready', 'database_instance', $restore->source_instance_id, ['restore_id' => $restore->id, 'new_instance_id' => $copy->id], $restore->organization_id);
        RestoreFinished::dispatch($restore->id, $restore->organization_id, (string) $restore->backup_id, $restore->server_id, (string) $source?->name, true, null);
    }

    /**
     * db.pitr.promote (key "db.pitr.promote:<restore id>:<decision>"): the decision takes effect.
     */
    public function promoted(string $key, bool $succeeded, ?string $error): void
    {
        [, $restoreId, $decision] = array_pad(explode(':', $key), 3, null);
        $restore = Restore::query()->find((string) $restoreId);

        if ($restore === null || $restore->status !== RestoreStatus::Running || ! in_array($decision, ['swap', 'keep'], true)) {
            return;
        }

        $copy = DatabaseInstance::query()->find($restore->restored_instance_id);
        $source = DatabaseInstance::query()->find($restore->source_instance_id);

        if (! $succeeded || $copy === null || ($decision === 'swap' && $source === null)) {
            $restore->forceFill(['status' => RestoreStatus::AwaitingDecision, 'decision' => null, 'error' => $error ?? 'The instance is gone.'])->save();

            return;
        }

        if ($decision === 'swap') {
            /** @var DatabaseInstance $source */
            ($this->takeOver)($source, $copy,
                'Replaced by a point-in-time restore to '.$restore->target_time?->toIso8601ZuluString().': stopped, kept with its data volume. Delete it once you verified the restored database.',
                null,
                [
                    'name' => $source->name,
                    'environment_id' => $source->environment_id,
                    'public_access' => $source->public_access,
                    'require_tls' => $source->require_tls,
                    'allowed_sources' => $source->allowed_sources,
                    ...collect($source->getAttributes())->only(['pitr_enabled', 'pitr_storage_provider_id', 'pitr_encryption_mode', 'pitr_age_recipient', 'pitr_window_days', 'pitr_base_interval_days'])->all(),
                ]);

            // The restored instance's history starts here: a base of its own (its log goes on from the restore).
            if ($copy->refresh()->pitr_enabled) {
                ($this->base)($copy, 'swap');
            }
        } else {
            $this->keep($copy, $source);
        }

        $restore->forceFill(['status' => RestoreStatus::Succeeded, 'error' => null])->save();
        $this->audit->record('databases.pitr_restore_decided', 'database_instance', $restore->source_instance_id, ['restore_id' => $restore->id, 'decision' => $decision, 'new_instance_id' => $copy->id], $restore->organization_id);
    }

    /**
     * Keep the copy as a database of its own: active under its own name, in the source's environment (on the canvas), with
     * the databases and users it holds (the source's, as they were at the target time).
     */
    private function keep(DatabaseInstance $copy, ?DatabaseInstance $source): void
    {
        $created = DB::transaction(function () use ($copy, $source) {
            $copy->forceFill(['status' => InstanceStatus::Active, 'status_message' => null, 'restored_from' => null, 'environment_id' => $source?->environment_id])->save();
            $created = [];

            if ($source === null) {
                return $created;
            }

            $map = [];

            foreach ($source->databases()->where('status', ResourceStatus::Active)->get() as $database) {
                $copied = $copy->databases()->create([
                    ...collect($database->getAttributes())->only(['organization_id', 'server_id', 'name', 'charset', 'collation'])->all(),
                    'status' => ResourceStatus::Active,
                ]);
                $map[$database->id] = $copied->id;
                $created[] = $copied;
            }

            foreach ($source->users()->where('status', ResourceStatus::Active)->with('grants')->get() as $user) {
                /** @var DatabaseUser $user */
                $copiedUser = $copy->users()->create([
                    ...collect($user->getAttributes())->only(['organization_id', 'server_id', 'username', 'host'])->all(),
                    'password' => $user->password,
                    'status' => ResourceStatus::Active,
                ]);

                foreach ($user->grants as $grant) {
                    /** @var Grant $grant */
                    if (isset($map[$grant->database_id])) {
                        $copiedUser->grants()->create(['database_id' => $map[$grant->database_id], 'privileges' => $grant->privileges]);
                    }
                }
            }

            return $created;
        });

        foreach ($created as $database) {
            /** @var Database $database */
            DatabaseCreated::dispatch($database->id, $database->organization_id, $database->server_id, $database->name, $copy->engine->value, null);
        }

        // On its environment's network under its own name.
        ($this->apply)($copy, background: true);
    }

    /** Remove a restored copy and its volume (a failed restore, or the user's discard). */
    public function discard(DatabaseInstance $copy): void
    {
        try {
            $this->lifecycle->delete($copy, deleteVolume: true, background: true);
        } catch (Throwable $e) {
            $copy->forceFill(['status_message' => 'Not removed: '.$e->getMessage()])->save();
        }
    }

    private static function sha(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Rows per table of each database (at most 50 databases, 500 tables each).
     *
     * @return ?array<string, array<string, int>>
     */
    private static function counts(mixed $counts): ?array
    {
        if (! is_array($counts)) {
            return null;
        }

        $clean = [];

        foreach (array_slice($counts, 0, 50, true) as $database => $tables) {
            if (! is_string($database) || ! is_array($tables)) {
                continue;
            }

            $clean[$database] = [];

            foreach (array_slice($tables, 0, 500, true) as $table => $rows) {
                if (is_string($table) && is_int($rows) && $rows >= 0) {
                    $clean[$database][mb_substr($table, 0, 200)] = $rows;
                }
            }
        }

        return $clean ?: null;
    }
}
