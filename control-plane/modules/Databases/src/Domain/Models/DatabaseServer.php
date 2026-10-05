<?php

namespace Falak\Databases\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Falak\Databases\Domain\Enums\Engine;

/**
 * The database engine running on a server (derived from the server's stack and agent facts).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $server_id
 * @property string $server_name
 * @property Engine $engine
 * @property ?string $version
 * @property string $version_source default|facts|manual
 * @property bool $dedicated server type "db" (managed database server)
 * @property bool $container_access containers on the server reach the engine (feature db.containers)
 * @property int $port
 * @property Carbon $created_at
 */
class DatabaseServer extends Model
{
    use HasUlids;

    protected $table = 'databases_servers';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'engine' => Engine::class,
            'dedicated' => 'boolean',
            'container_access' => 'boolean',
            'port' => 'integer',
        ];
    }

    /**
     * @return HasMany<Database, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(Database::class)->orderBy('name');
    }

    /**
     * @return HasMany<DatabaseUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(DatabaseUser::class)->orderBy('username');
    }

    /**
     * @return HasMany<BackupSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(BackupSchedule::class)->orderBy('name');
    }

    public function label(): string
    {
        return trim($this->engine->label().' '.($this->version ?? ''));
    }
}
