<?php

namespace Falak\Volumes\Domain\Models;

use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\Consistency;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A volume.archive snapshot (tar | zstd) in a storage provider. Plaintext until backup encryption (v0.10 step 4).
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
 * @property ?string $sha256
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
        return $this->status === BackupStatus::Succeeded && $this->sha256 !== null && $this->storage_provider_id !== null;
    }
}
