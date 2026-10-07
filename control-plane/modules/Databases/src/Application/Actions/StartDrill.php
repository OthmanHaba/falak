<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Contracts\DrillStatus;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Drill;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Security\BackupKeys;
use Falak\Kernel\Security\DecryptionFailed;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Starts a restore drill of a schedule (db.drill): the latest successful backup of the database least recently
 * verified is restored into a throwaway container of the instance's image digest, on the instance's server or the
 * schedule's drill server, and checked. The agent skips it when the server lacks the memory or disk.
 *
 * Customer-held backups can't be drilled: the key never reaches Falak. Those drills are recorded as skipped.
 */
final class StartDrill
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly ObjectStores $stores,
        private readonly BackupKeys $keys,
        private readonly ServerDirectory $servers,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException (manual drills only: no agent connected)
     */
    public function __invoke(BackupSchedule $schedule, bool $manual = false, ?string $actorId = null): Drill
    {
        $schedule->loadMissing('instance');
        $instance = $schedule->instance;
        $backup = $this->pick($schedule);
        $serverId = $schedule->drill_server_id ?? $instance->server_id;

        $drill = new Drill;
        $drill->id = strtolower((string) Str::ulid());
        $drill->forceFill([
            'organization_id' => $schedule->organization_id,
            'schedule_id' => $schedule->id,
            'backup_id' => $backup?->id,
            'database_instance_id' => $instance->id,
            'database_name' => $backup->database_name ?? null,
            'server_id' => $serverId,
            'server_name' => $this->servers->find($serverId)?->name ?? $instance->server_name,
            'status' => DrillStatus::Pending,
        ]);

        $skip = match (true) {
            $backup === null => 'No successful backup of this schedule to restore yet.',
            $backup->isCustomerHeld() => 'The backups\' keys are customer-held: Falak cannot restore them to check (restore one yourself with your age identity).',
            $instance->image_digest === null => 'The instance has no pinned image to restore into.',
            default => null,
        };

        if ($skip !== null) {
            return $this->skipped($drill, $skip);
        }

        try {
            $encryption = $this->keys->opening(BackupKeys::CP, $backup->wrapped_key, $backup->organization_id, $backup->id);
            $url = $this->stores->for($backup->storageProvider)->presignGet($backup->object_key, (int) config('databases.download_url_ttl', 21600));
        } catch (DecryptionFailed) {
            return $this->failed($drill, 'The backup\'s key can\'t be opened.');
        } catch (Throwable $e) {
            return $this->failed($drill, mb_substr('Storage: '.$e->getMessage(), 0, 1000));
        }

        $drill->save();
        $payload = CommandPayloads::drill($drill, $instance, $backup, $encryption, $url, $schedule->drill_query);
        $key = "db.drill:{$drill->id}";
        $timeout = (int) config('databases.timeouts.drill', 7200);

        $handle = $manual
            ? $this->commands->dispatch($serverId, 'db.drill', $payload, $timeout, $key, 'schedule')
            : $this->commands->tryDispatch($serverId, 'db.drill', $payload, $timeout, $key);

        if ($handle === null) {
            return $this->skipped($drill, AgentCommands::NOT_CONNECTED);
        }

        $drill->forceFill(['command_id' => $handle->id, 'started_at' => now()])->save();

        $this->audit->record('databases.drill_started', 'backup_schedule', $schedule->id, [
            'drill_id' => $drill->id,
            'backup_id' => $backup->id,
            'database' => $backup->database_name,
            'server_id' => $serverId,
            'manual' => $manual,
        ], $schedule->organization_id);

        return $drill;
    }

    /**
     * Each database's latest restorable backup; the one verified longest ago (never first) is drilled.
     */
    private function pick(BackupSchedule $schedule): ?Backup
    {
        return Backup::query()
            ->with('storageProvider')
            ->where('schedule_id', $schedule->id)
            ->where('status', BackupStatus::Succeeded)
            ->whereNotNull('encryption_mode')
            ->latest('finished_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->filter(fn (Backup $backup) => $backup->isRestorable() && $backup->storageProvider !== null)
            ->unique('database_id')
            ->sortBy(fn (Backup $backup) => [$backup->verified_at?->getTimestamp() ?? 0, $backup->database_name])
            ->first();
    }

    private function skipped(Drill $drill, string $reason): Drill
    {
        $drill->forceFill(['status' => DrillStatus::Skipped, 'reason' => $reason, 'finished_at' => now()])->save();

        return $drill;
    }

    private function failed(Drill $drill, string $error): Drill
    {
        $drill->forceFill(['status' => DrillStatus::Failed, 'error' => $error, 'finished_at' => now()])->save();

        SettleDrill::alert($drill);

        return $drill;
    }
}
