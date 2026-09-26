<?php

namespace Kiln\Sites\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A command run in a site's current release on one target (system.exec), with its outcome.
 *
 * @property string $id
 * @property string $site_id
 * @property string $server_id
 * @property string $command
 * @property string $unix_user
 * @property ?string $command_id
 * @property string $status fleet command status (queued, running, succeeded, failed, timed_out, cancelled)
 * @property ?int $exit_code
 * @property ?string $requested_by
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 */
class SiteCommand extends Model
{
    use HasUlids;

    protected $table = 'sites_commands';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
