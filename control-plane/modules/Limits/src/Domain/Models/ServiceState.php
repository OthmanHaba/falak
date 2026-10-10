<?php

namespace Falak\Limits\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * OOM kills and restarts of one service on one server.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $server_id
 * @property ?string $site_id
 * @property string $service_kind site | compose_service | worker | daemon | database
 * @property string $service_id
 * @property string $label
 * @property int $oom_kills
 * @property ?Carbon $last_oom_at
 * @property int $restarts
 * @property int $window_restarts restarts since window_started_at
 * @property ?Carbon $window_started_at
 * @property ?Carbon $last_restart_at
 * @property ?Carbon $restart_loop_at when ServiceRestartLoop was raised in the current window
 */
class ServiceState extends Model
{
    use HasUlids;

    protected $table = 'limits_service_states';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'oom_kills' => 'integer',
            'restarts' => 'integer',
            'window_restarts' => 'integer',
            'last_oom_at' => 'datetime',
            'window_started_at' => 'datetime',
            'last_restart_at' => 'datetime',
            'restart_loop_at' => 'datetime',
        ];
    }

    /**
     * Card badges: a recent OOM kill, a restart loop in the current window.
     *
     * @return list<string>
     */
    public function badges(): array
    {
        $window = (int) config('limits.restart_loop.window_minutes', 60);

        return array_values(array_filter([
            $this->last_oom_at !== null && $this->last_oom_at->gt(now()->subHours((int) config('limits.oom_badge_hours', 24))) ? 'OOM killed' : null,
            $this->restart_loop_at !== null && $this->window_started_at !== null && $this->window_started_at->gt(now()->subMinutes($window)) ? 'Restarting' : null,
        ]));
    }
}
