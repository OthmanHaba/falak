<?php

namespace Kiln\Databases\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kiln\Databases\Domain\Enums\BackupStatus;
use Kiln\Databases\Domain\Enums\Compression;
use Kiln\Databases\Domain\Enums\Engine;

/**
 * One dump shipped to object storage. Rows outlive the database, schedule and server.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $schedule_id
 * @property ?string $database_id
 * @property ?string $database_server_id
 * @property string $server_id
 * @property string $server_name
 * @property string $database_name
 * @property Engine $engine
 * @property ?string $storage_provider_id
 * @property string $object_key
 * @property Compression $compression
 * @property string $trigger manual|scheduled
 * @property BackupStatus $status
 * @property ?int $size_bytes
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

    protected $table = 'databases_backups';

    /** @var list<string> */
    protected $guarded = [];

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
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pruned_at' => 'datetime',
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
        return $this->status === BackupStatus::Succeeded && $this->sha256 !== null && $this->storage_provider_id !== null;
    }
}
