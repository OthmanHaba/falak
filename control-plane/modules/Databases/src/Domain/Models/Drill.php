<?php

namespace Falak\Databases\Domain\Models;

use Falak\Databases\Contracts\DrillStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A restore drill (db.drill): a schedule's latest backup restored into a throwaway instance and checked.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $schedule_id
 * @property ?string $backup_id
 * @property ?string $database_instance_id
 * @property ?string $database_name
 * @property ?string $server_id where it ran
 * @property ?string $server_name
 * @property DrillStatus $status
 * @property ?string $reason skipped: why
 * @property ?int $duration_ms
 * @property ?int $rto_estimate_seconds download + restore time
 * @property ?list<array{name: string, passed: bool, detail?: string}> $checks
 * @property ?string $error
 * @property ?string $command_id
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 * @property-read ?Backup $backup
 * @property-read ?BackupSchedule $schedule
 */
class Drill extends Model
{
    use HasUlids;

    protected $table = 'databases_drills';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DrillStatus::class,
            'checks' => 'array',
            'duration_ms' => 'integer',
            'rto_estimate_seconds' => 'integer',
            'started_at' => 'datetime',
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

    /**
     * @return BelongsTo<BackupSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BackupSchedule::class, 'schedule_id');
    }
}
