<?php

namespace Falak\Alerting\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A condition being observed (AlertConditions): since when it holds, and whether its alert was raised.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $key
 * @property Carbon $since
 * @property ?Carbon $raised_at
 * @property ?string $type the raised alert's type, title and link (for the default recovery)
 * @property ?string $title
 * @property ?string $url
 * @property Carbon $seen_at last observed
 */
class Condition extends Model
{
    public $timestamps = false;

    protected $table = 'alerting_conditions';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['since' => 'datetime', 'raised_at' => 'datetime', 'seen_at' => 'datetime'];
    }
}
