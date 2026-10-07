<?php

namespace Falak\Databases\Domain\Models;

use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Database\Eloquent\Collection;
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
 * @property string $username
 * @property string $password encrypted at rest; only revealed with databases.credentials.reveal
 * @property string $host MySQL account host ("%" = any)
 * @property ?string $site_id
 * @property ResourceStatus $status
 * @property ?string $status_message
 * @property ?string $command_id
 * @property int $revision
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property-read DatabaseInstance $instance
 * @property-read Collection<int, Grant> $grants
 */
class DatabaseUser extends Model
{
    use HasUlids;

    protected $table = 'databases_users';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => Sealed::class,
            'status' => ResourceStatus::class,
            'revision' => 'integer',
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
     * @return HasMany<Grant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class, 'user_id');
    }
}
