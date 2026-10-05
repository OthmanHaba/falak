<?php

namespace Falak\Projects\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A project a user starred (pinned first on the Projects dashboard). `user_id` is Identity's opaque id.
 *
 * @property string $user_id
 * @property string $project_id
 * @property string $organization_id
 * @property ?Carbon $created_at
 */
class Favorite extends Model
{
    protected $table = 'projects_favorites';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'user_id';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
