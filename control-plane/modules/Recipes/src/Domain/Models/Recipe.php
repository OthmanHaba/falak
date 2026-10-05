<?php

namespace Falak\Recipes\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An organization's saved script.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property ?string $description
 * @property string $script
 * @property string $user
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Recipe extends Model
{
    use HasUlids;

    protected $table = 'recipes_recipes';

    /** @var list<string> */
    protected $guarded = [];
}
