<?php

namespace Falak\Databases\Application\Actions;

use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Identity\Contracts\AuditLog;

final class SaveBackupSchedule
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array{name: string, storage_provider_id: string, database_ids: list<string>, cron: string, retention_count?: ?int, retention_days?: ?int, compression?: ?string, enabled?: ?bool}  $data
     */
    public function __invoke(DatabaseServer $server, array $data, ?BackupSchedule $schedule = null, ?string $actorId = null): BackupSchedule
    {
        if ($server->engine->isKeyValue()) {
            throw ValidationException::withMessages(['database_ids' => "Backups of {$server->engine->label()} instances are not supported yet (coming in a later release)."]);
        }

        $cron = trim(preg_replace('/\s+/', ' ', $data['cron']) ?? '');

        if (! CronExpression::isValidExpression($cron) || count(explode(' ', $cron)) !== 5) {
            throw ValidationException::withMessages(['cron' => 'Enter a 5-field cron expression, e.g. "0 3 * * *".']);
        }

        $providerExists = StorageProvider::query()->where('organization_id', $server->organization_id)->whereKey($data['storage_provider_id'])->exists();

        if (! $providerExists) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Choose a storage provider of this organization.']);
        }

        $databaseIds = array_values(array_unique($data['database_ids']));
        $valid = $server->databases()->whereIn('id', $databaseIds)->pluck('id')->all();

        if ($databaseIds === [] || count($valid) !== count($databaseIds)) {
            throw ValidationException::withMessages(['database_ids' => 'Choose one or more databases on this server.']);
        }

        $enabled = $data['enabled'] ?? true;

        $schedule = DB::transaction(function () use ($server, $data, $schedule, $cron, $valid, $enabled, $actorId) {
            $schedule ??= new BackupSchedule(['organization_id' => $server->organization_id, 'database_server_id' => $server->id, 'created_by' => $actorId]);
            $schedule->fill([
                'name' => $data['name'],
                'storage_provider_id' => $data['storage_provider_id'],
                'cron' => $cron,
                'retention_count' => $data['retention_count'] ?? null,
                'retention_days' => $data['retention_days'] ?? null,
                'compression' => Compression::from($data['compression'] ?? 'gzip'),
                'enabled' => $enabled,
                'next_run_at' => $enabled ? self::nextRun($cron) : null,
            ])->save();

            $schedule->databases()->sync($valid);

            return $schedule;
        });

        $this->audit->record($schedule->wasRecentlyCreated ? 'databases.backup_schedule_created' : 'databases.backup_schedule_updated', 'backup_schedule', $schedule->id, [
            'name' => $schedule->name,
            'cron' => $cron,
            'databases' => count($valid),
            'enabled' => $enabled,
        ], $server->organization_id);

        return $schedule;
    }

    public static function nextRun(string $cron, ?Carbon $after = null): Carbon
    {
        return Carbon::instance((new CronExpression($cron))->getNextRunDate(($after ?? now())->copy()->utc(), 0, false, 'UTC'));
    }
}
