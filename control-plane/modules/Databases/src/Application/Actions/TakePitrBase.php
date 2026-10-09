<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Events\PitrAlert;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStore;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Security\BackupKeys;
use Illuminate\Support\Str;

/**
 * A base backup for point-in-time recovery (db.pitr.base): `falak-db backup physical` of the whole instance, encrypted
 * (FKB1, a key of its own: cp sealed on the row, or the instance's age recipient) and PUT to the instance's PITR storage
 * through a presigned URL. A row of type base records where in the log it starts once the agent reports it.
 *
 * Taken when PITR is turned on, every pitr_base_interval_days (MaintainPitr), after a gap in the log chain, and on
 * demand. One at a time per instance.
 */
final class TakePitrBase
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly ObjectStores $stores,
        private readonly BackupKeys $keys,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  string  $reason  enabled|scheduled|gap|manual|swap
     * @return ?Backup the base (pending, or failed at once), or null when one is already running or PITR is off
     */
    public function __invoke(DatabaseInstance $instance, string $reason = 'scheduled', ?string $actorId = null): ?Backup
    {
        $provider = $instance->pitrStorageProvider;

        if (! $instance->pitr_enabled || ! $instance->supportsPitr()) {
            return null;
        }

        $running = Backup::query()->where('database_instance_id', $instance->id)->where('type', Backup::BASE)
            ->whereIn('status', [BackupStatus::Pending, BackupStatus::Running])->exists();

        if ($running) {
            return null;
        }

        // The next one, whatever happens to this one (a failure is retried sooner: HandleCommandOutcome).
        $instance->forceFill(['pitr_next_base_at' => now()->addDays(max(1, $instance->pitr_base_interval_days))])->save();

        $customer = $instance->pitr_encryption_mode === BackupKeys::CUSTOMER;
        $backup = new Backup;
        $backup->id = strtolower((string) Str::ulid());
        $now = now()->utc();
        $store = $provider !== null ? $this->stores->for($provider) : null;

        $backup->fill([
            'organization_id' => $instance->organization_id,
            'database_instance_id' => $instance->id,
            'server_id' => $instance->server_id,
            'server_name' => $instance->server_name,
            'instance_name' => $instance->name,
            'database_name' => $instance->name,
            'engine' => $instance->engine,
            'engine_version' => $instance->version,
            'storage_provider_id' => $provider?->id,
            'object_key' => (string) $store?->key(
                Str::slug($instance->name).'-'.substr($instance->id, -6),
                'pitr',
                'base',
                $now->format('Ymd\THis\Z').'-'.$backup->id.($instance->engine === Engine::PostgreSql ? '.tar' : '.xb').'.zst.fkb',
            ),
            'compression' => Compression::Zstd,
            'encryption_mode' => $customer ? BackupKeys::CUSTOMER : BackupKeys::CP,
            'age_recipient' => $customer ? $instance->pitr_age_recipient : null,
            'cipher' => 'aes-256-gcm',
            'type' => Backup::BASE,
            'trigger' => 'pitr',
            'status' => BackupStatus::Pending,
            'requested_by' => $actorId,
        ]);

        $failure = match (true) {
            $provider === null => 'Point-in-time recovery has no storage provider.',
            $customer && ! BackupKeys::validRecipient($instance->pitr_age_recipient) => 'The keys are customer-held but the age public key is missing or invalid.',
            ! $instance->isRunning() => "The database server is {$instance->status->value}.",
            default => null,
        };

        if ($failure !== null) {
            return $this->failed($instance, $backup, $failure);
        }

        if ($customer) {
            $encryption = BackupKeys::sealing($backup->id, (string) $instance->pitr_age_recipient);
        } else {
            [$encryption, $wrapped] = $this->keys->generate($backup->organization_id, $backup->id);
            $backup->wrapped_key = $wrapped;
        }

        /** @var ObjectStore $store */
        $payload = CommandPayloads::pitrBase($instance, $encryption, $store->presignPut($backup->object_key, (int) config('databases.upload_url_ttl', 43200)));
        $handle = $this->commands->tryDispatch($instance->server_id, 'db.pitr.base', $payload, (int) config('databases.timeouts.pitr_base', 14400), "db.pitr.base:{$backup->id}");

        if ($handle === null) {
            return $this->failed($instance, $backup, AgentCommands::NOT_CONNECTED);
        }

        $backup->fill(['command_id' => $handle->id, 'started_at' => now()])->save();

        $this->audit->record('databases.pitr_base_started', 'database_instance', $instance->id, [
            'backup_id' => $backup->id,
            'reason' => $reason,
            'encryption' => $backup->encryption_mode,
        ], $instance->organization_id);

        return $backup;
    }

    private function failed(DatabaseInstance $instance, Backup $backup, string $error): Backup
    {
        $backup->fill(['status' => BackupStatus::Failed, 'error' => $error, 'finished_at' => now()])->save();
        $instance->forceFill(['pitr_next_base_at' => now()->addMinutes((int) config('databases.pitr.base_retry_minutes', 60))])->save();
        PitrAlert::dispatch(PitrAlert::BASE_FAILED, $instance->organization_id, $instance->id, $instance->name, $instance->server_name, $error);

        return $backup;
    }
}
