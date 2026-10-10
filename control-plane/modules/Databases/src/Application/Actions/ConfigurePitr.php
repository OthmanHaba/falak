<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Security\BackupKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Point-in-time recovery settings of an instance: on or off, where bases and segments go (a storage provider), who
 * holds their keys (cp: Falak, sealed per segment; customer: an age recipient), how many days of recovery points to keep
 * and how often to take a base. The agent learns whether to ship the spool from db.instance.update (`pitr`); turning
 * it on takes a base at once.
 */
final class ConfigurePitr
{
    public function __construct(
        private readonly ApplyInstance $apply,
        private readonly TakePitrBase $base,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{enabled?: bool, storage_provider_id?: ?string, encryption_mode?: ?string, age_recipient?: ?string, window_days?: ?int, base_interval_days?: ?int}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(DatabaseInstance $instance, array $data, ?string $actorId = null): DatabaseInstance
    {
        if (! $instance->supportsPitr()) {
            throw ValidationException::withMessages(['pitr' => 'Point-in-time recovery is for PostgreSQL, MySQL and MariaDB.']);
        }

        if (! in_array($instance->status, [InstanceStatus::Active, InstanceStatus::Pending], true)) {
            throw ValidationException::withMessages(['pitr' => "The database is {$instance->status->value}."]);
        }

        $attributes = self::attributes($instance, $data);
        $was = $instance->pitr_enabled;
        $changesShipping = $was !== $attributes['pitr_enabled'] || $instance->pitr_storage_provider_id !== $attributes['pitr_storage_provider_id'];

        DB::transaction(function () use ($instance, $attributes, $changesShipping) {
            $instance->forceFill($attributes)->save();

            if ($changesShipping && $instance->status === InstanceStatus::Active) {
                ($this->apply)($instance, background: true);
            }
        });

        // Recovery starts from a base: take one now (also when the storage changed, the old one has nothing new).
        if ($instance->pitr_enabled && $changesShipping && $instance->isRunning()) {
            ($this->base)($instance, 'enabled', $actorId);
        }

        $this->audit->record('databases.pitr_configured', 'database_instance', $instance->id, [
            'enabled' => $instance->pitr_enabled,
            'storage_provider_id' => $instance->pitr_storage_provider_id,
            'encryption' => $instance->pitr_encryption_mode,
            'window_days' => $instance->pitr_window_days,
        ], $instance->organization_id);

        return $instance;
    }

    /**
     * The validated columns. Shared with instance creation (PITR on by default in production environments).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function attributes(DatabaseInstance $instance, array $data): array
    {
        $enabled = (bool) ($data['enabled'] ?? $instance->pitr_enabled);
        $providerId = array_key_exists('storage_provider_id', $data) ? ($data['storage_provider_id'] ?: null) : $instance->pitr_storage_provider_id;
        $mode = (string) (($data['encryption_mode'] ?? null) ?: $instance->pitr_encryption_mode ?: BackupKeys::CP);
        $recipient = array_key_exists('age_recipient', $data) ? (trim((string) $data['age_recipient']) ?: null) : $instance->pitr_age_recipient;
        $window = (int) ($data['window_days'] ?? $instance->pitr_window_days ?? config('databases.pitr.window_days', 7));
        $interval = (int) ($data['base_interval_days'] ?? $instance->pitr_base_interval_days ?? config('databases.pitr.base_interval_days', 7));

        if ($providerId !== null && ! StorageProvider::query()->whereKey($providerId)->where('organization_id', $instance->organization_id)->exists()) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Choose a storage provider of this organization.']);
        }

        if ($enabled && $providerId === null) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Point-in-time recovery needs a storage provider for its base backups and log segments.']);
        }

        if (! in_array($mode, [BackupKeys::CP, BackupKeys::CUSTOMER], true)) {
            throw ValidationException::withMessages(['encryption_mode' => 'Choose who holds the keys: Falak (cp) or you (customer).']);
        }

        if ($mode === BackupKeys::CUSTOMER && ! BackupKeys::validRecipient($recipient)) {
            throw ValidationException::withMessages(['age_recipient' => 'Paste the age public key (age1…) the segments and bases are encrypted to.']);
        }

        if ($window < 1 || $window > 35) {
            throw ValidationException::withMessages(['window_days' => 'Keep between 1 and 35 days of recovery points.']);
        }

        if ($interval < 1 || $interval > $window) {
            throw ValidationException::withMessages(['base_interval_days' => 'Take a base backup at least once per window (every 1 to '.$window.' days).']);
        }

        return [
            'pitr_enabled' => $enabled,
            'pitr_storage_provider_id' => $providerId,
            'pitr_encryption_mode' => $mode,
            'pitr_age_recipient' => $mode === BackupKeys::CUSTOMER ? $recipient : null,
            'pitr_window_days' => $window,
            'pitr_base_interval_days' => $interval,
        ];
    }
}
