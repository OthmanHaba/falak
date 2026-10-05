<?php

namespace Falak\Deployments\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The release a site's server runs (its `current`), updated when a deployment activates or reverts it there.
 *
 * @property string $site_id
 * @property string $server_id
 * @property string $release_id
 * @property ?string $deployment_id the deployment that switched it
 * @property Carbon $updated_at
 */
class ServerRelease extends Model
{
    protected $table = 'deployments_server_releases';

    protected $primaryKey = 'site_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    public static function record(string $siteId, string $serverId, string $releaseId, ?string $deploymentId): void
    {
        self::query()->upsert([[
            'site_id' => strtolower($siteId),
            'server_id' => strtolower($serverId),
            'release_id' => strtolower($releaseId),
            'deployment_id' => $deploymentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['site_id', 'server_id'], ['release_id', 'deployment_id', 'updated_at']);
    }
}
