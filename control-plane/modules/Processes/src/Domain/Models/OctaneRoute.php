<?php

namespace Falak\Processes\Domain\Models;

use Falak\Processes\Domain\Enums\OctaneRouteStatus;
use Falak\Sites\Contracts\OctaneServer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $server_id
 * @property OctaneServer $octane_server
 * @property int $port
 * @property OctaneRouteStatus $status
 * @property ?string $probe_command_id
 * @property ?string $error
 * @property ?Carbon $checked_at
 * @property ?Carbon $listening_at
 * @property ?Carbon $draining_since
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OctaneRoute extends Model
{
    use HasUlids;

    protected $table = 'processes_octane_routes';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'octane_server' => OctaneServer::class,
            'port' => 'integer',
            'status' => OctaneRouteStatus::class,
            'checked_at' => 'datetime',
            'listening_at' => 'datetime',
            'draining_since' => 'datetime',
        ];
    }
}
