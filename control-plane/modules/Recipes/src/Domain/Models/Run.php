<?php

namespace Falak\Recipes\Domain\Models;

use Falak\Kernel\Security\Casts\SealedArray;
use Falak\Recipes\Domain\Enums\RunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution of a recipe (or built-in) on N servers. Script and user are snapshotted.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $recipe_id
 * @property ?string $builtin
 * @property string $recipe_name
 * @property string $script
 * @property string $user
 * @property ?array<string, string> $env
 * @property int $timeout_s
 * @property ?string $requested_by
 * @property RunStatus $status
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 */
class Run extends Model
{
    use HasUlids;

    protected $table = 'recipes_runs';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['env'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            // Run-time variables may carry secrets (tokens, passwords).
            'env' => SealedArray::class,
            'timeout_s' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RunTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(RunTarget::class)->orderBy('server_name');
    }
}
