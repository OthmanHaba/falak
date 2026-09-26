<?php

namespace Kiln\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kiln\Edge\Domain\Enums\ApplyStatus;

/**
 * Last edge.caddy.apply sent to a server.
 *
 * @property string $server_id
 * @property string $organization_id
 * @property ?string $payload_sha256
 * @property ?string $command_id
 * @property ApplyStatus $status
 * @property ?string $config_sha256
 * @property ?int $routes
 * @property ?string $error
 * @property ?Carbon $dispatched_at
 * @property ?Carbon $applied_at
 */
class ServerState extends Model
{
    protected $table = 'edge_server_states';

    protected $primaryKey = 'server_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ApplyStatus::class, 'routes' => 'integer', 'dispatched_at' => 'datetime', 'applied_at' => 'datetime'];
    }
}
