<?php

namespace Kiln\Insights\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One reported exception (volume table, pruned by bucket_date).
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property string $issue_id
 * @property ?string $server_id
 * @property string $type
 * @property string $message
 * @property ?string $stacktrace
 * @property bool $handled
 * @property ?string $user_hash
 * @property ?string $event_type
 * @property ?string $route_or_name
 * @property ?string $trace_id
 * @property ?string $span_id
 */
class ExceptionOccurrence extends Model
{
    public $timestamps = false;

    protected $table = 'insights_exceptions';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'minute' => 'datetime',
            'bucket_date' => 'date',
            'handled' => 'boolean',
        ];
    }
}
