<?php

namespace Falak\Databases\Application\Actions;

use Cron\CronExpression;
use Falak\Databases\Application\DrillQuery;
use Falak\Databases\Contracts\DrillFrequency;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Security\BackupKeys;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a database backup schedule: when (cron, UTC), where (a storage provider), how long backups are kept,
 * who holds the keys (cp, or the customer's age recipient) and how often its latest backup is restored in a drill
 * (default: weekly for an instance in a production environment, off otherwise).
 */
final class SaveBackupSchedule
{
    public function __construct(
        private readonly ProjectDirectory $projects,
        private readonly ServerDirectory $servers,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{name: string, storage_provider_id: string, database_ids: list<string>, cron: string, retention_count?: ?int, retention_days?: ?int, enabled?: ?bool, encryption_mode?: ?string, age_recipient?: ?string, drill?: ?string, drill_query?: ?string, drill_server_id?: ?string}  $data
     */
    public function __invoke(DatabaseInstance $instance, array $data, ?BackupSchedule $schedule = null, ?string $actorId = null): BackupSchedule
    {
        $cron = trim(preg_replace('/\s+/', ' ', $data['cron']) ?? '');

        if (! CronExpression::isValidExpression($cron) || count(explode(' ', $cron)) !== 5) {
            throw ValidationException::withMessages(['cron' => 'Enter a 5-field cron expression, e.g. "0 3 * * *".']);
        }

        $providerExists = StorageProvider::query()->where('organization_id', $instance->organization_id)->whereKey($data['storage_provider_id'])->exists();

        if (! $providerExists) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Choose a storage provider of this organization.']);
        }

        $databaseIds = array_values(array_unique($data['database_ids']));
        $valid = $instance->databases()->whereIn('id', $databaseIds)->pluck('id')->all();

        if ($databaseIds === [] || count($valid) !== count($databaseIds)) {
            throw ValidationException::withMessages(['database_ids' => 'Choose one or more databases of this database server.']);
        }

        $enabled = $data['enabled'] ?? true;
        $protection = $this->protection($instance, $data, $schedule);

        $schedule = DB::transaction(function () use ($instance, $data, $schedule, $cron, $valid, $enabled, $actorId, $protection) {
            $schedule ??= new BackupSchedule(['organization_id' => $instance->organization_id, 'database_instance_id' => $instance->id, 'created_by' => $actorId]);
            $drillChanged = ! $schedule->exists || $schedule->drill !== $protection['drill'];
            $schedule->fill([
                'name' => $data['name'],
                'storage_provider_id' => $data['storage_provider_id'],
                'cron' => $cron,
                'retention_count' => $data['retention_count'] ?? null,
                'retention_days' => $data['retention_days'] ?? null,
                'enabled' => $enabled,
                'next_run_at' => $enabled ? self::nextRun($cron) : null,
                ...$protection,
            ]);

            if ($protection['drill'] === DrillFrequency::Off || ! $enabled) {
                $schedule->next_drill_at = null;
            } elseif ($drillChanged || $schedule->next_drill_at === null) {
                // The first drill follows the next backup.
                $schedule->next_drill_at = self::nextRun($cron)->addHour();
            }

            $schedule->save();
            $schedule->databases()->sync($valid);

            return $schedule;
        });

        $this->audit->record($schedule->wasRecentlyCreated ? 'databases.backup_schedule_created' : 'databases.backup_schedule_updated', 'backup_schedule', $schedule->id, [
            'name' => $schedule->name,
            'cron' => $cron,
            'databases' => count($valid),
            'enabled' => $enabled,
            'encryption' => $schedule->encryption_mode,
            'drill' => $schedule->drill->value,
        ], $instance->organization_id);

        return $schedule;
    }

    /**
     * The key mode and drill settings, validated. A new schedule without a drill setting takes its environment's
     * default.
     *
     * @param  array<string, mixed>  $data
     * @return array{encryption_mode: string, age_recipient: ?string, drill: DrillFrequency, drill_query: ?string, drill_server_id: ?string}
     */
    private function protection(DatabaseInstance $instance, array $data, ?BackupSchedule $schedule): array
    {
        $mode = $data['encryption_mode'] ?? $schedule?->encryption_mode ?? BackupKeys::CP;
        $recipient = isset($data['age_recipient']) ? trim((string) $data['age_recipient']) : $schedule?->age_recipient;

        if (! in_array($mode, [BackupKeys::CP, BackupKeys::CUSTOMER], true)) {
            throw ValidationException::withMessages(['encryption_mode' => 'Choose who holds the backup keys.']);
        }

        if ($mode === BackupKeys::CUSTOMER && ! BackupKeys::validRecipient($recipient)) {
            throw ValidationException::withMessages(['age_recipient' => 'Enter an age public key (age1…), e.g. from `age-keygen`.']);
        }

        $drill = isset($data['drill']) ? DrillFrequency::tryFrom((string) $data['drill']) : ($schedule?->drill ?? DrillFrequency::default($this->production($instance)));

        if ($drill === null) {
            throw ValidationException::withMessages(['drill' => 'Choose off, weekly or monthly.']);
        }

        $query = array_key_exists('drill_query', $data) ? trim((string) $data['drill_query']) : (string) $schedule?->drill_query;

        if ($query !== '' && $instance->engine->isKeyValue()) {
            throw ValidationException::withMessages(['drill_query' => 'Redis and Valkey drills check the key count; there is no query.']);
        }

        if ($query !== '' && ($problem = DrillQuery::problem($query)) !== null) {
            throw ValidationException::withMessages(['drill_query' => $problem]);
        }

        $server = array_key_exists('drill_server_id', $data) ? ($data['drill_server_id'] ?: null) : $schedule?->drill_server_id;

        if ($server !== null) {
            $found = $this->servers->find(strtolower((string) $server));

            if ($found === null || $found->organizationId !== $instance->organization_id) {
                throw ValidationException::withMessages(['drill_server_id' => 'Choose a server of this organization.']);
            }

            $server = $found->id === $instance->server_id ? null : $found->id;
        }

        return [
            'encryption_mode' => $mode,
            'age_recipient' => $mode === BackupKeys::CUSTOMER ? $recipient : null,
            'drill' => $drill,
            'drill_query' => $query !== '' ? DrillQuery::normalize($query) : null,
            'drill_server_id' => $server,
        ];
    }

    /** Whether the instance lives in a production environment of a project. */
    private function production(DatabaseInstance $instance): bool
    {
        return $instance->environment_id !== null && ($this->projects->environment($instance->environment_id)?->isProduction ?? false);
    }

    public static function nextRun(string $cron, ?Carbon $after = null): Carbon
    {
        return Carbon::instance((new CronExpression($cron))->getNextRunDate(($after ?? now())->copy()->utc(), 0, false, 'UTC'));
    }
}
