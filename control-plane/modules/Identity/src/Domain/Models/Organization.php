<?php

namespace Falak\Identity\Domain\Models;

use Falak\Identity\Contracts\Data\OrganizationData;
use Falak\Identity\Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant boundary: every other module scopes its data by organization_id.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $owner_id
 * @property bool $personal
 */
#[UseFactory(OrganizationFactory::class)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'identity_organizations';

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'owner_id', 'personal'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['personal' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'identity_memberships')
            ->using(Membership::class)
            ->withTimestamps();
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function toData(): OrganizationData
    {
        return new OrganizationData(
            id: $this->id,
            name: $this->name,
            slug: $this->slug,
            ownerId: $this->owner_id,
            personal: $this->personal,
        );
    }
}
