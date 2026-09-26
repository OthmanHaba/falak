<?php

namespace Kiln\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named group of organization members (e.g. "Backend", "On-call").
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property ?string $description
 */
class Team extends Model
{
    use HasUlids;

    protected $table = 'identity_teams';

    /** @var list<string> */
    protected $fillable = ['organization_id', 'name', 'description'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'identity_team_members')->withTimestamps();
    }
}
