<?php

namespace Falak\Databases\Domain\Models;

use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Kernel\Security\BackupKeys;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dump shipped to object storage, compressed and encrypted (FKB1; docs/BACKUPS.md). Rows outlive the database,
 * schedule and server. Backups from before encryption (no encryption_mode) can't be restored.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $schedule_id
 * @property ?string $database_id
 * @property ?string $database_instance_id
 * @property string $server_id
 * @property string $server_name
 * @property string $database_name
 * @property Engine $engine
 * @property ?string $storage_provider_id
 * @property string $object_key
 * @property Compression $compression
 * @property ?string $encryption_mode cp|customer
 * @property ?string $wrapped_key cp: the data key sealed under the organization's key (BackupKeys)
 * @property ?string $age_recipient customer: the recipient the key was encrypted to
 * @property ?string $cipher aes-256-gcm
 * @property ?string $plaintext_sha256 the dump's SHA-256 (sha256 is the stored file's)
 * @property ?array<string, int> $table_counts row count per table (Redis / Valkey: keys) when it was taken
 * @property ?string $drill_status the last drill of this backup (DrillStatus)
 * @property ?Carbon $verified_at when a drill last restored it successfully
 * @property string $trigger manual|scheduled|pitr
 * @property string $type logical|base (a physical backup of the whole instance, for point-in-time recovery)
 * @property ?string $log_start bases: postgres start_wal, mysql/mariadb the binlog the base starts in
 * @property ?string $log_stop bases: postgres stop_wal
 * @property ?Carbon $base_started_at bases: falak-db's start (the server's clock)
 * @property ?int $pitr_epoch bases: the instance's log epoch when it was taken
 * @property ?Carbon $base_finished_at bases: falak-db's end, the earliest point it restores to
 * @property BackupStatus $status
 * @property ?int $size_bytes
 * @property ?int $uncompressed_bytes
 * @property ?string $sha256
 * @property ?int $duration_ms
 * @property ?string $command_id
 * @property ?string $error
 * @property ?string $prune_error
 * @property ?string $requested_by
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $pruned_at
 * @property Carbon $created_at
 * @property-read ?StorageProvider $storageProvider
 */
class Backup extends Model
{
    use HasUlids;

    public const LOGICAL = 'logical';

    public const BASE = 'base';

    protected $table = 'databases_backups';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['wrapped_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'engine' => Engine::class,
            'compression' => Compression::class,
            'status' => BackupStatus::class,
            'size_bytes' => 'integer',
            'uncompressed_bytes' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pruned_at' => 'datetime',
            'verified_at' => 'datetime',
            'base_started_at' => 'datetime',
            'base_finished_at' => 'datetime',
            'pitr_epoch' => 'integer',
            'table_counts' => 'array',
        ];
    }

    /**
     * @return BelongsTo<StorageProvider, $this>
     */
    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class);
    }

    public function isRestorable(): bool
    {
        return $this->status === BackupStatus::Succeeded && $this->sha256 !== null && $this->storage_provider_id !== null
            && ($this->encryption_mode === BackupKeys::CUSTOMER || ($this->encryption_mode === BackupKeys::CP && $this->wrapped_key !== null));
    }

    public function isBase(): bool
    {
        return $this->type === self::BASE;
    }

    public function isCustomerHeld(): bool
    {
        return $this->encryption_mode === BackupKeys::CUSTOMER;
    }
}
