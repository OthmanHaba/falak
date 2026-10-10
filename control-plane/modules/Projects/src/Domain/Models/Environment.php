<?php

namespace Falak\Projects\Domain\Models;

use Falak\Projects\Contracts\Data\EnvironmentData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $name
 * @property string $slug
 * @property bool $is_production
 * @property bool $is_preview a pull request's preview (Previews)
 * @property bool $is_fork_preview a preview of a pull request from a fork: no secrets
 * @property ?list<string> $shared_services service names a preview resolves from its base environment (forked_from_id)
 * @property ?string $forked_from_id
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project $project
 * @property-read Collection<int, Service> $services
 * @property-read Collection<int, Group> $groups
 */
class Environment extends Model
{
    use HasUlids;

    /** Slugs that collide with /projects/{project}/… routes. */
    public const RESERVED_SLUGS = ['settings', 'environments', 'services', 'service', 'canvas', 'edit', 'create', 'previews', 'volumes'];

    protected $table = 'projects_environments';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_production' => 'boolean', 'is_preview' => 'boolean', 'is_fork_preview' => 'boolean', 'shared_services' => 'array'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class)->orderBy('created_at')->orderBy('id');
    }

    public function toData(): EnvironmentData
    {
        return new EnvironmentData($this->id, $this->organization_id, $this->project_id, $this->name, $this->slug, $this->is_production, $this->forked_from_id,
            (bool) $this->is_preview, (bool) $this->is_fork_preview);
    }
}
