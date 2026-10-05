<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Falak\Edge\Domain\Enums\LbPolicy;

/**
 * A load-balancer server (type lb) terminating TLS for a site and proxying to its targets.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $server_id
 * @property LbPolicy $policy
 * @property ?string $health_uri
 * @property int $backend_port
 * @property array<string, int> $weights target server id => weight (1-10, default 1)
 */
class LoadBalancer extends Model
{
    use HasUlids;

    public const MAX_WEIGHT = 10;

    protected $table = 'edge_load_balancers';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['policy' => LbPolicy::class, 'weights' => 'array', 'backend_port' => 'integer'];
    }

    public function weightFor(string $serverId): int
    {
        return max(1, min(self::MAX_WEIGHT, (int) ($this->weights[$serverId] ?? 1)));
    }
}
