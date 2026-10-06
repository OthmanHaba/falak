<?php

namespace Falak\Volumes\Domain\Models;

use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property ?string $server_id null: a shared path, on every server of its site
 * @property string $name
 * @property VolumeKind $kind
 * @property ?string $docker_name
 * @property ?string $host_path
 * @property ?int $size_limit_bytes
 * @property ?int $used_bytes
 * @property ?Carbon $used_at
 * @property bool $protected
 * @property ?array<string, string> $labels
 * @property ?array<string, mixed> $options
 * @property VolumeStatus $status
 * @property ?string $status_message
 * @property ?string $command_id
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property-read Collection<int, Attachment> $attachments
 * @property-read Collection<int, BackupSchedule> $schedules
 */
class Volume extends Model
{
    use HasUlids;

    /** Where sized volumes are mounted on their server (agent volumes.Root). */
    public const SIZED_ROOT = '/var/lib/falak/volumes';

    /** Docker volume names (agent and schema rule). */
    public const DOCKER_NAME = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/';

    /** Names users give volumes. */
    public const NAME = '/^[a-z0-9][a-z0-9_.-]{0,62}$/';

    protected $table = 'volumes_volumes';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => VolumeKind::class,
            'status' => VolumeStatus::class,
            'size_limit_bytes' => 'integer',
            'used_bytes' => 'integer',
            'used_at' => 'datetime',
            'protected' => 'boolean',
            'labels' => 'array',
            'options' => 'array',
        ];
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * @return HasMany<BackupSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(BackupSchedule::class);
    }

    /** A compose stack's volume (declared in its file): compose creates it and its file decides where it mounts. */
    public function composeKey(): ?string
    {
        $key = $this->options['compose']['key'] ?? null;

        return is_string($key) ? $key : null;
    }

    public function composeSiteId(): ?string
    {
        $site = $this->options['compose']['site_id'] ?? null;

        return is_string($site) ? $site : null;
    }

    /** `external: true` in a compose file: Docker's volume is not the stack's, Falak never deletes it. */
    public function external(): bool
    {
        return ($this->options['external'] ?? false) === true;
    }

    /** shared_path volumes: a file (.env) rather than a directory. */
    public function sharedFile(): bool
    {
        return ($this->options['type'] ?? 'directory') === 'file';
    }

    /** Where containers mount it from: the Docker volume's name, or the host path. */
    public function mountSource(): string
    {
        return match ($this->kind) {
            VolumeKind::Docker => (string) $this->docker_name,
            VolumeKind::Sized => self::SIZED_ROOT.'/'.$this->id,
            default => (string) $this->host_path,
        };
    }

    /**
     * The agent's reference to it (volume.* `volume`).
     *
     * @return array{id: string, kind: string, name?: string, path?: string}
     */
    public function ref(): array
    {
        return array_filter([
            'id' => $this->id,
            'kind' => $this->kind->value,
            'name' => $this->kind === VolumeKind::Docker ? $this->docker_name : null,
            'path' => in_array($this->kind, [VolumeKind::Bind, VolumeKind::SharedPath], true) ? $this->host_path : null,
        ], fn ($value) => $value !== null);
    }

    /** A database container's data (step 3): read through the database's own backups, never cloned or browsed. */
    public function holdsDatabase(): bool
    {
        return $this->attachments->contains(fn (Attachment $attachment) => $attachment->attachable_type === AttachableType::Database);
    }

    /** Fraction of its limit in use (sized volumes; null when unknown). */
    public function usage(): ?float
    {
        return $this->size_limit_bytes !== null && $this->size_limit_bytes > 0 && $this->used_bytes !== null
            ? $this->used_bytes / $this->size_limit_bytes
            : null;
    }
}
