<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Container upstream reported by deploy.container.swap for a site on a server.
 *
 * @property int $id
 * @property string $site_id
 * @property string $server_id
 * @property string $upstream
 */
class Upstream extends Model
{
    protected $table = 'edge_upstreams';

    /** @var list<string> */
    protected $guarded = [];
}
