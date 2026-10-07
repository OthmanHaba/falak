<?php

namespace Falak\Databases\Domain\Models;

use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One database container (`falak-db-<id>`) on a server: a Falak image of the engine, its data on a sized volume, its
 * config tuned to the memory limit (docs/DB_IMAGES.md). SQL instances hold databases and users; a Redis / Valkey
 * instance has one keyspace (one Database row) and its `default` user.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $server_id
 * @property string $server_name
 * @property ?string $environment_id the project environment whose Docker network it joins
 * @property string $name
 * @property Engine $engine
 * @property string $version major
 * @property string $image tag reference
 * @property ?string $image_digest sha256:… the agent pulled (or the pin it was given)
 * @property string $hostname DNS name on the environment network
 * @property int $port inside the container
 * @property ?int $host_port published on 127.0.0.1 (and private addresses); null once retired
 * @property ?list<string> $published_addresses private addresses the port is published on, as the agent confirmed
 * @property ?list<string> $pending_published_addresses addresses waiting to be applied (a restart: Docker binds ports at creation)
 * @property ?string $network_command_id the update applying them
 * @property ?list<string> $allowed_sources CIDRs allowed to reach the port publicly (public access allowlist)
 * @property ?list<string> $firewall_sources every source last sent to the agent's DOCKER-USER rules
 * @property ?string $previous_password Redis / Valkey: still valid until password_overlap_until (sealed)
 * @property ?Carbon $password_overlap_until
 * @property ?string $replaced_by the instance a major upgrade moved its data to
 * @property bool $public_access
 * @property bool $require_tls
 * @property ?string $volume_id
 * @property int $memory_bytes
 * @property ?float $cpus
 * @property ?array<string, mixed> $settings falak-db settings (docs/DB_IMAGES.md "Settings")
 * @property bool $pitr_enabled
 * @property string $root_password superuser / root / Redis `default` password; sealed at rest
 * @property ?string $next_root_password a rotation the agent has not confirmed yet
 * @property bool $delete_volume deleting: the data volume goes too
 * @property ?Carbon $tls_expires_at
 * @property ?list<string> $tls_hostnames what the certificate is valid for
 * @property InstanceStatus $status
 * @property ?string $status_message
 * @property ?string $health healthy|unhealthy|starting|none|stopped (heartbeats)
 * @property ?Carbon $health_at
 * @property ?string $command_id
 * @property ?string $upgrade_of the instance a major upgrade replaces
 * @property ?Carbon $retire_at
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property-read Collection<int, Database> $databases
 * @property-read Collection<int, DatabaseUser> $users
 */
class DatabaseInstance extends Model
{
    use HasUlids;

    protected $table = 'databases_instances';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['root_password', 'next_root_password', 'previous_password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'engine' => Engine::class,
            'status' => InstanceStatus::class,
            'port' => 'integer',
            'host_port' => 'integer',
            'published_addresses' => 'array',
            'pending_published_addresses' => 'array',
            'allowed_sources' => 'array',
            'firewall_sources' => 'array',
            'public_access' => 'boolean',
            'require_tls' => 'boolean',
            'memory_bytes' => 'integer',
            'cpus' => 'float',
            'settings' => 'array',
            'pitr_enabled' => 'boolean',
            'root_password' => Sealed::class,
            'next_root_password' => Sealed::class,
            'previous_password' => Sealed::class,
            'password_overlap_until' => 'datetime',
            'delete_volume' => 'boolean',
            'tls_expires_at' => 'datetime',
            'tls_hostnames' => 'array',
            'health_at' => 'datetime',
            'retire_at' => 'datetime',
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

    public function container(): string
    {
        return "falak-db-{$this->id}";
    }

    /** The environment's Docker network, or null. */
    public function network(): ?string
    {
        return $this->environment_id !== null ? 'falak-env-'.strtolower($this->environment_id) : null;
    }

    public function label(): string
    {
        return "{$this->engine->label()} {$this->version}";
    }

    /** Commands may run against it (its container exists and is meant to run). */
    public function isRunning(): bool
    {
        return $this->status === InstanceStatus::Active;
    }
}
