<?php

namespace Falak\Recovery\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $key
 * @property Carbon $dismissed_until
 */
class Dismissal extends Model
{
    use HasUlids;

    protected $table = 'recovery_dismissals';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['dismissed_until' => 'datetime'];
    }
}
