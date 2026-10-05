<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Origin lock-down of a server: `closed` (no inbound web traffic; only with a running Cloudflare Tunnel) or
 * `cloudflare` (web ports open to Cloudflare's ranges only, for proxied names).
 *
 * @property string $server_id
 * @property string $organization_id
 * @property string $mode
 */
class OriginLock extends Model
{
    public const CLOSED = 'closed';

    public const CLOUDFLARE = 'cloudflare';

    protected $table = 'edge_origin_locks';

    protected $primaryKey = 'server_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];
}
