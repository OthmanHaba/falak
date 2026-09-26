<?php

namespace Kiln\Databases\Domain\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kiln\Databases\Domain\Enums\ResourceStatus;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $database_server_id
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
 * @property-read DatabaseServer $databaseServer
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
            'password' => 'encrypted',
            'status' => ResourceStatus::class,
            'revision' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DatabaseServer, $this>
     */
    public function databaseServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class);
    }

    /**
     * @return HasMany<Grant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class, 'user_id');
    }
}
