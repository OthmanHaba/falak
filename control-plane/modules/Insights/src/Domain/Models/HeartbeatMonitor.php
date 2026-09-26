<?php

namespace Kiln\Insights\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kiln\Insights\Domain\Support\CronSchedule;

/**
 * A scheduled task observed through cron heartbeats (created on its first heartbeat).
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $site_id
 * @property ?string $server_id
 * @property string $source_id
 * @property string $job
 * @property ?string $schedule
 * @property string $timezone
 * @property ?int $grace_seconds
 * @property bool $enabled
 * @property ?string $last_status
 * @property ?int $last_exit_code
 * @property ?int $last_duration_ms
 * @property ?Carbon $last_run_at
 * @property ?Carbon $last_scheduled_at
 * @property ?Carbon $next_expected_at
 * @property ?Carbon $missed_at
 */
class HeartbeatMonitor extends Model
{
    use HasUlids;

    protected $table = 'insights_heartbeat_monitors';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['timezone' => 'UTC', 'enabled' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'grace_seconds' => 'integer',
            'last_exit_code' => 'integer',
            'last_duration_ms' => 'integer',
            'last_run_at' => 'datetime',
            'last_scheduled_at' => 'datetime',
            'next_expected_at' => 'datetime',
            'missed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<HeartbeatRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(HeartbeatRun::class, 'monitor_id');
    }

    public function graceSeconds(): int
    {
        return $this->grace_seconds ?? (int) config('insights.heartbeats.grace_seconds', 120);
    }

    public function cron(): ?CronSchedule
    {
        return CronSchedule::parse($this->schedule, $this->timezone);
    }
}
