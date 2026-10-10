<?php

namespace Falak\Databases\Domain\Models;

use Falak\Databases\Contracts\DrillFrequency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $database_instance_id
 * @property string $storage_provider_id
 * @property string $name
 * @property string $cron 5-field cron expression, evaluated in UTC
 * @property ?int $retention_count keep at most N successful backups per database
 * @property ?int $retention_days delete successful backups older than N days
 * @property string $encryption_mode cp|customer (BackupKeys)
 * @property ?string $age_recipient customer: the age X25519 recipient backups are encrypted to
 * @property DrillFrequency $drill
 * @property ?string $drill_query a read-only SELECT that must return rows (SQL engines)
 * @property ?string $drill_server_id another server of the organization to run drills on
 * @property ?Carbon $last_drill_at
 * @property ?Carbon $next_drill_at
 * @property bool $enabled
 * @property ?Carbon $last_run_at
 * @property ?Carbon $next_run_at
 * @property ?string $created_by
 * @property-read DatabaseInstance $instance
 * @property-read StorageProvider $storageProvider
 * @property-read Collection<int, Database> $databases
 * @property-read Collection<int, Drill> $drills
 */
class BackupSchedule extends Model
{
    use HasUlids;

    protected $table = 'databases_backup_schedules';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'drill' => DrillFrequency::class,
            'enabled' => 'boolean',
            'retention_count' => 'integer',
            'retention_days' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_drill_at' => 'datetime',
            'next_drill_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DatabaseInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(DatabaseInstance::class, 'database_instance_id');
    }

    /**
     * @return BelongsTo<StorageProvider, $this>
     */
    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class);
    }

    /**
     * @return HasMany<Drill, $this>
     */
    public function drills(): HasMany
    {
        return $this->hasMany(Drill::class, 'schedule_id')->latest()->orderByDesc('id');
    }

    /**
     * @return BelongsToMany<Database, $this>
     */
    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(Database::class, 'databases_backup_schedule_database', 'schedule_id', 'database_id')->orderBy('name');
    }
}
