<?php

namespace Falak\Sites\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Falak\Sites\Contracts\Data\SiteTargetData;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;

/**
 * A server a site is deployed to (the multi-server deployment group).
 *
 * @property string $id
 * @property string $site_id
 * @property string $server_id
 * @property TargetRole $role
 * @property TargetStatus $status
 * @property ?string $status_message
 * @property ?string $step user | pool | cleanup
 * @property ?string $command_id
 */
class SiteTarget extends Model
{
    use HasUlids;

    public const STEP_USER = 'user';

    public const STEP_POOL = 'pool';

    /** Installing the site's JavaScript runtime (Bun / Deno). */
    public const STEP_RUNTIME = 'runtime';

    public const STEP_CLEANUP = 'cleanup';

    protected $table = 'sites_targets';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TargetRole::class,
            'status' => TargetStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isLeader(): bool
    {
        return $this->role === TargetRole::Leader;
    }

    public function toData(): SiteTargetData
    {
        return new SiteTargetData($this->id, $this->site_id, $this->server_id, $this->role, $this->status, $this->status_message);
    }
}
