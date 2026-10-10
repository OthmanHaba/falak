<?php

namespace Falak\Volumes\Application\Actions;

use Cron\CronExpression;
use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\DrillFrequency;
use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Security\BackupKeys;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Volumes\Domain\Enums\Consistency;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a volume's backup schedule: when (cron, UTC), where (a storage provider), how (consistency), how
 * long backups are kept (count and/or days), who holds the keys (cp, or the customer's age recipient) and how often the
 * latest archive is restored in a drill (default: weekly when a site of a production environment uses the volume).
 */
final class SaveBackupSchedule
{
    public function __construct(
        private readonly BackupStorage $storage,
        private readonly ProjectDirectory $projects,
        private readonly ServerDirectory $servers,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(
        Volume $volume,
        string $storageProviderId,
        string $cron,
        ?int $retentionCount,
        ?int $retentionDays,
        Consistency $consistency,
        bool $enabled = true,
        ?BackupSchedule $schedule = null,
        ?string $actorId = null,
        ?string $encryptionMode = null,
        ?string $ageRecipient = null,
        ?string $drill = null,
        ?string $drillServerId = null,
    ): BackupSchedule {
        if (! $volume->kind->portable()) {
            throw ValidationException::withMessages(['volume' => 'Only Docker and sized volumes can be backed up.']);
        }

        if ($volume->holdsDatabase()) {
            throw ValidationException::withMessages(['volume' => 'A database’s data volume is backed up with the database.']);
        }

        if ($this->storage->find($volume->organization_id, $storageProviderId) === null) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Choose a storage provider of this organization.']);
        }

        if (! CronExpression::isValidExpression($cron) || count(preg_split('/\s+/', trim($cron)) ?: []) !== 5) {
            throw ValidationException::withMessages(['cron' => 'Use a 5-field cron expression (UTC), e.g. "0 3 * * *".']);
        }

        $mode = $encryptionMode ?? $schedule?->encryption_mode ?? BackupKeys::CP;
        $recipient = $ageRecipient !== null ? trim($ageRecipient) : $schedule?->age_recipient;

        if (! in_array($mode, [BackupKeys::CP, BackupKeys::CUSTOMER], true)) {
            throw ValidationException::withMessages(['encryption_mode' => 'Choose who holds the backup keys.']);
        }

        if ($mode === BackupKeys::CUSTOMER && ! BackupKeys::validRecipient($recipient)) {
            throw ValidationException::withMessages(['age_recipient' => 'Enter an age public key (age1…), e.g. from `age-keygen`.']);
        }

        $frequency = $drill !== null ? DrillFrequency::tryFrom($drill) : ($schedule?->drill ?? DrillFrequency::default($this->production($volume)));

        if ($frequency === null) {
            throw ValidationException::withMessages(['drill' => 'Choose off, weekly or monthly.']);
        }

        $drillServer = $drillServerId !== null ? ($drillServerId !== '' ? strtolower($drillServerId) : null) : $schedule?->drill_server_id;

        if ($drillServer !== null) {
            $found = $this->servers->find($drillServer);

            if ($found === null || $found->organizationId !== $volume->organization_id) {
                throw ValidationException::withMessages(['drill_server_id' => 'Choose a server of this organization.']);
            }

            $drillServer = $found->id === $volume->server_id ? null : $found->id;
        }

        $schedule ??= new BackupSchedule(['organization_id' => $volume->organization_id, 'volume_id' => $volume->id, 'created_by' => $actorId]);
        $drillChanged = ! $schedule->exists || $schedule->drill !== $frequency;
        $schedule->forceFill([
            'storage_provider_id' => strtolower($storageProviderId),
            'cron' => trim($cron),
            'retention_count' => $retentionCount,
            'retention_days' => $retentionDays,
            'consistency' => $consistency,
            'enabled' => $enabled,
            'next_run_at' => $enabled ? self::nextRun($cron) : null,
            'encryption_mode' => $mode,
            'age_recipient' => $mode === BackupKeys::CUSTOMER ? $recipient : null,
            'drill' => $frequency,
            'drill_server_id' => $drillServer,
        ]);

        if ($frequency === DrillFrequency::Off || ! $enabled) {
            $schedule->next_drill_at = null;
        } elseif ($drillChanged || $schedule->next_drill_at === null) {
            // The first drill follows the next backup.
            $schedule->next_drill_at = self::nextRun($cron)->addHour();
        }

        $schedule->save();

        $this->audit->record($schedule->wasRecentlyCreated ? 'volumes.backup_schedule_created' : 'volumes.backup_schedule_updated', 'volume', $volume->id, [
            'name' => $volume->name,
            'cron' => $schedule->cron,
            'enabled' => $enabled,
            'encryption' => $schedule->encryption_mode,
            'drill' => $schedule->drill->value,
        ], $volume->organization_id);

        return $schedule;
    }

    /** Whether a site of a production environment uses the volume. */
    private function production(Volume $volume): bool
    {
        foreach ($volume->attachments as $attachment) {
            $siteId = $attachment->siteId();
            $service = $siteId !== null ? $this->projects->projectOf(ServiceKind::Site, $siteId) : null;

            if ($service !== null && ($this->projects->environment($service->environmentId)?->isProduction ?? false)) {
                return true;
            }
        }

        return false;
    }

    public static function nextRun(string $cron, ?Carbon $after = null): Carbon
    {
        return Carbon::instance((new CronExpression(trim($cron)))->getNextRunDate(($after ?? now())->copy()->utc(), 0, false, 'UTC'));
    }
}
