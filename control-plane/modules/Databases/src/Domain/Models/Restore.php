<?php

namespace Falak\Databases\Domain\Models;

use Falak\Databases\Domain\Enums\RestoreStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $backup_id
 * @property string $database_instance_id
 * @property string $server_id
 * @property string $type backup|pitr
 * @property ?string $database_name backup restores: the target database
 * @property ?Carbon $target_time pitr: the time recovered to
 * @property ?string $source_instance_id pitr: the instance whose history was restored
 * @property ?string $restored_instance_id pitr: the new instance
 * @property ?int $segments pitr: log segments replayed
 * @property ?array<string, array<string, int>> $table_counts pitr: rows per table of each database, as restored
 * @property ?string $decision pitr: swap|keep|discard
 * @property ?Carbon $decided_at
 * @property ?string $decided_by
 * @property RestoreStatus $status
 * @property ?int $bytes
 * @property ?int $duration_ms
 * @property ?string $command_id
 * @property ?string $error
 * @property ?list<string> $warnings
 * @property ?string $requested_by
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 * @property-read Backup $backup
 */
class Restore extends Model
{
    public const BACKUP = 'backup';

    public const PITR = 'pitr';

    use HasUlids;

    protected $table = 'databases_restores';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RestoreStatus::class,
            'bytes' => 'integer',
            'duration_ms' => 'integer',
            'warnings' => 'array',
            'finished_at' => 'datetime',
            'target_time' => 'datetime',
            'segments' => 'integer',
            'table_counts' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Backup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }
}
