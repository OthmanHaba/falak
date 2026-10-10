<?php

namespace Falak\Volumes\Domain\Models;

use Falak\Databases\Contracts\DrillFrequency;
use Falak\Volumes\Domain\Enums\Consistency;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string $encryption_mode cp|customer (BackupKeys)
 * @property ?string $age_recipient customer: the age X25519 recipient archives are encrypted to
 * @property DrillFrequency $drill
 * @property ?string $drill_server_id another server of the organization to run drills on
 * @property ?Carbon $last_drill_at
 * @property ?Carbon $next_drill_at
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
            'drill' => DrillFrequency::class,
            'last_drill_at' => 'datetime',
            'next_drill_at' => 'datetime',
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

    /**
     * @return HasMany<VolumeDrill, $this>
     */
    public function drills(): HasMany
    {
        return $this->hasMany(VolumeDrill::class, 'schedule_id')->latest()->orderByDesc('id');
    }
}
