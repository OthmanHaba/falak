<?php

namespace Falak\Volumes\Domain\Models;

use Falak\Kernel\Security\BackupKeys;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\Consistency;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A volume.archive snapshot in a storage provider: a tar stream, compressed and encrypted (FKB1; docs/BACKUPS.md) with
 * its own data key, held sealed on the row (cp) or by the customer (age). Archives from before encryption can't be
 * restored.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $volume_id
 * @property string $volume_name
 * @property VolumeKind $volume_kind
 * @property ?string $server_id
 * @property ?string $schedule_id
 * @property ?string $storage_provider_id
 * @property string $object_key
 * @property Consistency $consistency
 * @property string $trigger manual|scheduled|move|clone
 * @property BackupStatus $status
 * @property ?int $size_bytes
 * @property ?int $uncompressed_bytes
 * @property ?int $volume_size_bytes the sized volume's limit when it was taken (a restore creates one as large)
 * @property ?string $sha256 the stored (encrypted) file's
 * @property ?string $plaintext_sha256 the tar stream's
 * @property ?int $files files in the archive
 * @property ?string $encryption_mode cp|customer
 * @property ?string $wrapped_key cp: the data key sealed under the organization's key (BackupKeys)
 * @property ?string $age_recipient customer: the recipient the key was encrypted to
 * @property ?string $cipher aes-256-gcm
 * @property ?string $compression zstd
 * @property ?string $drill_status the last drill of this backup
 * @property ?Carbon $verified_at when a drill last restored it
 * @property ?int $duration_ms
 * @property ?string $error
 * @property ?string $prune_error
 * @property ?string $command_id
 * @property ?string $requested_by
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $pruned_at
 * @property Carbon $created_at
 * @property-read ?Volume $volume
 */
class VolumeBackup extends Model
{
    use HasUlids;

    protected $table = 'volumes_backups';

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
            'volume_kind' => VolumeKind::class,
            'consistency' => Consistency::class,
            'status' => BackupStatus::class,
            'size_bytes' => 'integer',
            'uncompressed_bytes' => 'integer',
            'volume_size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pruned_at' => 'datetime',
            'verified_at' => 'datetime',
            'files' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Volume, $this>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    public function restorable(): bool
    {
        return $this->status === BackupStatus::Succeeded && $this->sha256 !== null && $this->storage_provider_id !== null
            && ($this->encryption_mode === BackupKeys::CUSTOMER || ($this->encryption_mode === BackupKeys::CP && $this->wrapped_key !== null));
    }

    public function isCustomerHeld(): bool
    {
        return $this->encryption_mode === BackupKeys::CUSTOMER;
    }
}
