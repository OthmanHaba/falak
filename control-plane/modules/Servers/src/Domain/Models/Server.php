<?php

namespace Kiln\Servers\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Database\Factories\ServerFactory;
use Kiln\Servers\Domain\Enums\PhpVersionStatus;
use Kiln\Servers\Domain\Stack\Stack;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property ServerType $type
 * @property ServerStatus $status
 * @property ?string $status_message
 * @property string $provider
 * @property ?string $provider_credential_id
 * @property ?string $provider_server_id
 * @property ?string $region
 * @property ?string $size
 * @property ?string $image
 * @property ?string $ipv4
 * @property ?string $ipv6
 * @property ?string $private_ipv4
 * @property int $ssh_port
 * @property string $timezone
 * @property Stack $stack
 * @property ?array<string, mixed> $facts
 * @property ?string $os
 * @property ?string $arch
 * @property ?int $cpus
 * @property ?int $memory_bytes
 * @property ?int $disk_bytes
 * @property ?string $install_command
 * @property ?string $provision_command_id
 * @property int $provision_attempts
 * @property ?string $ssh_sync_command_id
 * @property ?Carbon $provisioned_at
 * @property ?string $created_by
 * @property Carbon $created_at
 */
#[UseFactory(ServerFactory::class)]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'servers_servers';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['install_command'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ServerType::class,
            'status' => ServerStatus::class,
            'facts' => 'array',
            'install_command' => 'encrypted',
            'provisioned_at' => 'datetime',
            'ssh_port' => 'integer',
        ];
    }

    /**
     * @return Attribute<Stack, Stack|array<string, mixed>>
     */
    protected function stack(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => Stack::fromArray($value ? (array) json_decode($value, true) : []),
            set: fn (Stack|array $value) => json_encode($value instanceof Stack ? $value->toArray() : $value, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return HasMany<PhpVersion, $this>
     */
    public function phpVersions(): HasMany
    {
        return $this->hasMany(PhpVersion::class)->orderBy('version');
    }

    /**
     * @return BelongsToMany<SshKey, $this>
     */
    public function sshKeys(): BelongsToMany
    {
        return $this->belongsToMany(SshKey::class, 'servers_server_ssh_keys')->withPivot('unix_user')->withTimestamps();
    }

    public function isCustom(): bool
    {
        return $this->provider === 'custom';
    }

    public function defaultPhp(): ?PhpVersion
    {
        return $this->phpVersions->firstWhere('is_default', true);
    }

    /**
     * PHP versions that should exist on the host (converged by provision.apply).
     *
     * @return list<string>
     */
    public function desiredPhpVersions(): array
    {
        return $this->phpVersions()
            ->whereIn('status', [PhpVersionStatus::Installing, PhpVersionStatus::Installed])
            ->pluck('version')
            ->map(fn ($v) => (string) $v)
            ->values()
            ->all();
    }

    public function toData(): ServerData
    {
        $installed = $this->phpVersions->where('status', PhpVersionStatus::Installed);

        return new ServerData(
            id: $this->id,
            organizationId: $this->organization_id,
            name: $this->name,
            type: $this->type,
            status: $this->status,
            provider: $this->provider,
            ipv4: $this->ipv4,
            ipv6: $this->ipv6,
            privateIpv4: $this->private_ipv4,
            arch: $this->arch,
            phpVersions: $installed->pluck('version')->map(fn ($v) => (string) $v)->values()->all(),
            defaultPhpVersion: $installed->firstWhere('is_default', true)?->version,
            phpRuntime: $this->stack->phpRuntime,
            nodeVersion: $this->stack->node,
            databaseEngine: $this->stack->database,
            cacheEngine: $this->stack->cache,
            docker: $this->stack->docker,
            unixUser: (string) config('servers.unix_user', 'kiln'),
        );
    }
}
