<?php

namespace Falak\Volumes\Domain\Models;

use Falak\Databases\Contracts\DrillStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A volume restore drill (volume.drill): a schedule's latest archive restored into a scratch directory and checked.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $schedule_id
 * @property ?string $backup_id
 * @property ?string $volume_id
 * @property ?string $volume_name
 * @property ?string $server_id where it ran
 * @property DrillStatus $status
 * @property ?string $reason
 * @property ?int $duration_ms
 * @property ?int $rto_estimate_seconds download + restore time
 * @property ?list<array{name: string, passed: bool, detail?: string}> $checks
 * @property ?string $error
 * @property ?string $command_id
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 * @property-read ?BackupSchedule $schedule
 */
class VolumeDrill extends Model
{
    use HasUlids;

    protected $table = 'volumes_drills';

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
     * @return BelongsTo<BackupSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BackupSchedule::class, 'schedule_id');
    }
}
