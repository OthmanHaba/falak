<?php

namespace Falak\Volumes\Domain\Models;

use Falak\Volumes\Domain\Enums\Consistency;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $volume_id
 * @property ?string $storage_provider_id a Databases storage provider (BackupStorage); null once it was deleted
 * @property string $cron 5-field cron expression, evaluated in UTC
 * @property ?int $retention_count keep at most N successful backups
 * @property ?int $retention_days delete successful backups older than N days
 * @property Consistency $consistency
 * @property bool $enabled
 * @property ?Carbon $last_run_at
 * @property ?Carbon $next_run_at
 * @property ?string $created_by
 * @property-read Volume $volume
 */
class BackupSchedule extends Model
{
    use HasUlids;

    protected $table = 'volumes_backup_schedules';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consistency' => Consistency::class,
            'enabled' => 'boolean',
            'retention_count' => 'integer',
            'retention_days' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Volume, $this>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }
}
