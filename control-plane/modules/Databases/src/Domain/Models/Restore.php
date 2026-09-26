<?php

namespace Kiln\Databases\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kiln\Databases\Domain\Enums\RestoreStatus;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $backup_id
 * @property string $database_server_id
 * @property string $server_id
 * @property string $database_name
 * @property RestoreStatus $status
 * @property ?int $bytes
 * @property ?int $duration_ms
 * @property ?string $command_id
 * @property ?string $error
 * @property ?string $requested_by
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 * @property-read Backup $backup
 */
class Restore extends Model
{
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
            'finished_at' => 'datetime',
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
