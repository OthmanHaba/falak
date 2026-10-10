<?php

namespace Falak\Databases\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A break in an instance's log chain (`falak-db binlog-rotate` exit 4): binlogs purged before they were spooled
 * (missing) or the numbering started over (reset). Recovery can't cross it; the next base backup resolves it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $database_instance_id
 * @property string $kind wal|binlog
 * @property string $gap missing|reset
 * @property ?string $from
 * @property ?string $to
 * @property string $detail
 * @property Carbon $detected_at
 * @property ?Carbon $resolved_at
 */
class PitrGap extends Model
{
    use HasUlids;

    protected $table = 'databases_pitr_gaps';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
