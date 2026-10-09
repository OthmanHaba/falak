<?php

namespace Falak\Databases\Application\Actions;

use Carbon\CarbonImmutable;
use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\InstanceCertificates;
use Falak\Databases\Application\InstancePorts;
use Falak\Databases\Application\Passwords;
use Falak\Databases\Application\PitrTimeline;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\PitrSegment;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Security\BackupKeys;
use Falak\Kernel\Security\DecryptionFailed;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Restore an instance to a point in time (db.pitr.restore). The running instance is never touched: a NEW instance (the
 * source's image digest, a new volume sized for the base and the replayed log) gets the newest base before the time
 * and the segments after it, replays to the time and starts read-only, published on 127.0.0.1 only and on no Docker
 * network. Its restore waits for a decision (DecidePitrRestore): swap it in, keep it as a new database, or discard it.
 *
 * Keys: cp-held bases and segments are unwrapped here and travel in the payload (forgotten once the command settled);
 * customer-held ones need the customer's age identity, given for this restore only and never stored.
 */
final class RestoreToTime
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly ObjectStores $stores,
        private readonly BackupKeys $keys,
        private readonly PitrTimeline $timeline,
        private readonly InstancePorts $ports,
        private readonly InstanceCertificates $certificates,
        private readonly ServiceVolumes $volumes,
        private readonly AuditLog $audit,
    ) {}

    /** The copy's read-only account, for inspection (the source's accounts can write once it is promoted). */
    public const INSPECTION_USER = 'falak_inspect';

    /**
     * $target null: the latest point (everything shipped). A target at or after the end of the newest range is the
     * latest point too: the copy is recovered to the end of the shipped log, not to a time there is no log for.
     *
     * @throws ValidationException
     */
    public function __invoke(DatabaseInstance $source, ?CarbonImmutable $target, ?string $actorId = null, #[\SensitiveParameter] ?string $identity = null): Restore
    {
        if (! $source->supportsPitr()) {
            throw ValidationException::withMessages(['target_time' => 'Point-in-time recovery is for PostgreSQL, MySQL and MariaDB.']);
        }

        // MySQL / MariaDB binlogs have whole seconds: replay stops before the first event at or after T.
        $target = $target !== null && $source->engine->isMysqlFamily() ? $target->startOfSecond() : $target;
        $plan = $this->timeline->plan($source, $target);

        if ($plan === null) {
            $range = $this->timeline->for($source);

            throw ValidationException::withMessages(['target_time' => $range['from'] === null || $target === null
                ? 'There is no recovery point yet: PITR needs a base backup and the log shipped after it.'
                : "{$target->toIso8601ZuluString()} is not in a recovery range (from {$range['from']} to {$range['to']}, gaps excepted)."]);
        }

        $latest = $plan['latest'];
        $target = $plan['to'];

        $base = $plan['base'];
        $segments = $plan['segments'];
        $customer = $base->isCustomerHeld() || collect($segments)->contains(fn (PitrSegment $segment) => $segment->isCustomerHeld());
        $identity = $identity !== null ? trim($identity) : null;

        if ($customer && ! BackupKeys::validIdentity($identity)) {
            throw ValidationException::withMessages(['identity' => 'The keys are customer-held: paste the age identity (AGE-SECRET-KEY-1…) that matches the recipient.']);
        }

        if ($base->storageProvider === null || collect($segments)->contains(fn (PitrSegment $segment) => $segment->storageProvider === null)) {
            throw ValidationException::withMessages(['target_time' => 'The storage provider of the base or of a segment was deleted.']);
        }

        $busy = Restore::query()->where('source_instance_id', $source->id)->whereIn('status', [RestoreStatus::Pending, RestoreStatus::Running])->exists();

        if ($busy) {
            throw ValidationException::withMessages(['target_time' => 'A point-in-time restore of this database is already running.']);
        }

        try {
            $objects = [
                'base' => $this->object($base->storageProvider, $base->object_key, $base->sha256, $base->plaintext_sha256, (int) $base->size_bytes, (int) $base->uncompressed_bytes,
                    $this->encryption((string) $base->encryption_mode, $base->wrapped_key, $base->organization_id, $base->id)),
                'segments' => array_map(fn (PitrSegment $segment) => ['name' => $segment->name, ...$this->object($segment->storageProvider, $segment->object_key, $segment->sha256,
                    $segment->plaintext_sha256, (int) $segment->size_bytes, (int) $segment->plaintext_bytes,
                    $this->encryption($segment->encryption_mode, $segment->wrapped_key, $segment->organization_id, $segment->id, $segment->database_instance_id))], $segments),
            ];
        } catch (DecryptionFailed) {
            throw ValidationException::withMessages(['target_time' => 'A key of the base or of a segment can\'t be opened (it belongs to another organization or was changed).']);
        }

        $inspection = Passwords::generate();

        $restore = DB::transaction(function () use ($source, $target, $latest, $base, $segments, $objects, $actorId, $identity, $customer, $inspection) {
            DatabaseInstance::query()->where('server_id', $source->server_id)->lockForUpdate()->get(['id']);

            $copy = new DatabaseInstance;
            $copy->id = strtolower((string) Str::ulid());
            $copy->forceFill([
                ...collect($source->getAttributes())->only(['organization_id', 'server_id', 'server_name', 'engine', 'version', 'image', 'image_digest', 'port', 'memory_bytes', 'cpus'])->all(),
                // Read-only in the engine's config too (it survives a restart), no scheduled events while it is inspected.
                'settings' => [...(array) ($source->settings ?? []), 'read_only' => true, 'event_scheduler' => false],
                // The restored data keeps the source's accounts and their passwords.
                'root_password' => $source->root_password,
                'name' => substr($source->name, 0, 30).'-pitr-'.substr($copy->id, -5),
                'hostname' => "falak-db-{$copy->id}",
                'host_port' => $this->ports->allocate($source->server_id),
                'status' => InstanceStatus::Pending,
                'status_message' => "Restoring {$source->name} to {$target->toIso8601ZuluString()}.",
                'restored_from' => $source->id,
                'pitr_enabled' => false,
                'created_by' => $actorId,
            ])->save();

            // Room for the base (stored and unpacked) and the replayed log, and at least the source's size.
            $need = (int) ceil(1.25 * ((int) $base->uncompressed_bytes + collect($segments)->sum(fn (PitrSegment $s) => (int) $s->plaintext_bytes)))
                + (int) $base->size_bytes + 512 * 1024 ** 2;
            $sourceDisk = (int) ($source->volume_id !== null ? $this->volumes->find($source->volume_id)?->sizeLimitBytes : 0);
            $disk = (int) min((int) config('databases.disk.max'), max($source->engine->defaultDisk(), $sourceDisk, (int) ceil($need / 1024 ** 3) * 1024 ** 3));
            $volume = $this->volumes->createSized($source->organization_id, $source->server_id, CreateInstance::volumeName($copy), $disk, ['db-instance' => $copy->id], protected: true, actorId: $actorId);
            $copy->forceFill(['volume_id' => $volume->id])->save();

            $restore = Restore::query()->create([
                'organization_id' => $source->organization_id,
                'type' => Restore::PITR,
                'backup_id' => $base->id,
                'database_instance_id' => $source->id,
                'source_instance_id' => $source->id,
                'restored_instance_id' => $copy->id,
                'server_id' => $source->server_id,
                'target_time' => $target,
                'to_latest' => $latest,
                'inspection_password' => $inspection,
                'segments' => count($segments),
                'status' => RestoreStatus::Pending,
                'requested_by' => $actorId,
            ]);

            $spec = CommandPayloads::instance($copy, $this->certificates->issue($copy))['instance'];
            unset($spec['pitr']);

            $handle = $this->commands->dispatch($source->server_id, 'db.pitr.restore', array_filter([
                'restore' => $restore->id,
                'instance' => $spec,
                'password' => $copy->root_password,
                'identity' => $customer ? $identity : null,
                'base' => $objects['base'],
                'segments' => $objects['segments'],
                // The latest point: no target, everything shipped is replayed.
                'target_time' => $latest ? null : $target->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'inspection' => ['username' => self::INSPECTION_USER, 'password' => $inspection],
                'databases' => $source->databases()->where('status', ResourceStatus::Active)->pluck('name')->values()->all(),
            ], fn ($value) => $value !== null), (int) config('databases.timeouts.pitr_restore', 21600), "db.pitr.restore:{$restore->id}", 'target_time');

            $restore->forceFill(['command_id' => $handle->id])->save();
            $copy->forceFill(['command_id' => $handle->id])->save();

            return $restore;
        });

        $this->audit->record('databases.pitr_restore_requested', 'database_instance', $source->id, [
            'restore_id' => $restore->id,
            'target_time' => $target->toIso8601ZuluString(),
            'latest' => $latest,
            'base_id' => $base->id,
            'segments' => count($segments),
            'new_instance_id' => $restore->restored_instance_id,
            'encryption' => $customer ? BackupKeys::CUSTOMER : BackupKeys::CP,
        ], $source->organization_id);

        return $restore;
    }

    /**
     * @param  array<string, string>  $encryption
     * @return array<string, mixed>
     */
    private function object(StorageProvider $provider, string $key, ?string $sha, ?string $plainSha, int $size, int $plain, array $encryption): array
    {
        return array_filter([
            'url' => $this->stores->for($provider)->presignGet($key, (int) config('databases.download_url_ttl', 21600)),
            'sha256' => $sha,
            'plaintext_sha256' => $plainSha,
            'size_bytes' => $size,
            'plaintext_bytes' => $plain > 0 ? $plain : null,
            'encryption' => $encryption,
        ], fn ($value) => $value !== null);
    }

    /**
     * cp: the unwrapped key; customer: only the key id (the restore's identity opens every object).
     *
     * @return array<string, string>
     *
     * @throws DecryptionFailed
     */
    private function encryption(string $mode, ?string $wrapped, string $organizationId, string $id, ?string $instanceId = null): array
    {
        return $mode === BackupKeys::CUSTOMER
            ? ['mode' => 'age', 'key_id' => $id]
            : $this->keys->opening(BackupKeys::CP, $wrapped, $organizationId, $id, instanceId: $instanceId);
    }
}
