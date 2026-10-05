<?php

namespace Falak\Projects\Domain\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Falak\Projects\Contracts\Data\ProjectData;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property ?string $description
 * @property ?string $icon
 * @property bool $is_default
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Environment> $environments
 * @property-read Collection<int, Service> $services
 */
class Project extends Model
{
    use HasUlids;

    protected $table = 'projects_projects';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /**
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class)->orderByDesc('is_production')->orderBy('name');
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function production(): ?Environment
    {
        return $this->environments->firstWhere('is_production', true) ?? $this->environments->first();
    }

    public function toData(): ProjectData
    {
        return new ProjectData($this->id, $this->organization_id, $this->name, $this->description, $this->icon, $this->is_default);
    }
}
