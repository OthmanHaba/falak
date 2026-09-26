<?php

namespace Kiln\Insights\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $monitor_id
 * @property string $status
 * @property ?int $exit_code
 * @property ?int $duration_ms
 * @property Carbon $scheduled_at
 * @property Carbon $at
 */
class HeartbeatRun extends Model
{
    public $timestamps = false;

    protected $table = 'insights_heartbeat_runs';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'at' => 'datetime', 'bucket_date' => 'date', 'exit_code' => 'integer', 'duration_ms' => 'integer'];
    }
}
