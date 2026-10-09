<?php

namespace Falak\Databases\Domain\Models;

use Falak\Kernel\Security\BackupKeys;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One WAL segment or binlog of an instance with point-in-time recovery, shipped by the agent from its spool as an FKB1
 * object with a key of its own (docs/BACKUPS.md "Point-in-time recovery"). A row is made when the agent asks for its
 * upload URL; shipped_at is set once the agent reported the upload.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $database_instance_id
 * @property string $server_id
 * @property string $kind wal|binlog
 * @property string $name
 * @property ?string $storage_provider_id
 * @property string $object_key
 * @property string $encryption_mode cp|customer
 * @property ?string $wrapped_key cp: the data key sealed under the organization's key (BackupKeys, AAD segment id)
 * @property ?string $age_recipient
 * @property ?int $size_bytes
 * @property ?string $sha256 the stored file's
 * @property ?int $plaintext_bytes
 * @property string $plaintext_sha256 the spool file's
 * @property ?Carbon $end_time when falak-db spooled it (the server's clock)
 * @property ?Carbon $shipped_at
 * @property Carbon $created_at
 * @property-read ?StorageProvider $storageProvider
 */
class PitrSegment extends Model
{
    use HasUlids;

    public const KINDS = ['wal', 'binlog'];

    protected $table = 'databases_pitr_segments';

    /** end_time keeps the spool file's microseconds: restore targets are that precise (postgres). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

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
            'size_bytes' => 'integer',
            'plaintext_bytes' => 'integer',
            'end_time' => 'datetime',
            'shipped_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StorageProvider, $this>
     */
    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class);
    }

    public function isCustomerHeld(): bool
    {
        return $this->encryption_mode === BackupKeys::CUSTOMER;
    }
}
