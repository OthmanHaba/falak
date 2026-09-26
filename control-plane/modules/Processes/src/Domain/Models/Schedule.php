<?php

namespace Kiln\Processes\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A custom scheduled job of a site (runs on the leader, or on every server of the site).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $name
 * @property string $command
 * @property string $expression 5-field cron, @hourly/@daily/@weekly/@monthly/@yearly or @every <duration>
 * @property string $timezone
 * @property ?string $user
 * @property string $overlap allow|skip
 * @property int $timeout
 * @property bool $heartbeat
 * @property bool $enabled
 * @property bool $all_servers
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Schedule extends Model
{
    use HasUlids;

    protected $table = 'processes_schedules';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['timezone' => 'UTC', 'overlap' => 'skip', 'timeout' => 3600, 'heartbeat' => true, 'enabled' => true, 'all_servers' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'timeout' => 'integer',
            'heartbeat' => 'boolean',
            'enabled' => 'boolean',
            'all_servers' => 'boolean',
        ];
    }
}
