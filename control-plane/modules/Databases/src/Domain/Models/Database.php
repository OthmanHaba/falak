<?php

namespace Falak\Databases\Domain\Models;

use Falak\Databases\Domain\Enums\ResourceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $database_instance_id
 * @property string $server_id
 * @property string $name
 * @property ?string $charset
 * @property ?string $collation
 * @property ?string $site_id opaque Sites ULID
 * @property ResourceStatus $status
 * @property ?string $status_message
 * @property ?string $command_id
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property-read DatabaseInstance $instance
 */
class Database extends Model
{
    use HasUlids;

    protected $table = 'databases_databases';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ResourceStatus::class];
    }

    /**
     * @return BelongsTo<DatabaseInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(DatabaseInstance::class, 'database_instance_id');
    }

    /**
     * @return HasMany<Grant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }
}
