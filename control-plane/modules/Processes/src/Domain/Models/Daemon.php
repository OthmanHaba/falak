<?php

namespace Falak\Processes\Domain\Models;

use Falak\Kernel\Security\Casts\SealedArray;
use Falak\Limits\Contracts\ResourceLimits;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An arbitrary long-running command supervised on the site's servers.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $name
 * @property string $command
 * @property ?string $directory absolute, defaults to the site's current release
 * @property ?string $user defaults to the site's unix user
 * @property int $instances
 * @property string $restart always|on-failure|never
 * @property string $stop_signal
 * @property int $stop_timeout
 * @property array<string, string> $env encrypted
 * @property ?array<string, mixed> $limits resource limits (Limits' ResourceLimits JSON) of its slice
 * @property ?list<string> $server_ids
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Daemon extends Model
{
    use HasUlids;
    use ServerTargets;

    public const RESTART_POLICIES = ['always', 'on-failure', 'never'];

    public const STOP_SIGNALS = ['TERM', 'INT', 'QUIT', 'HUP', 'KILL', 'USR1', 'USR2'];

    protected $table = 'processes_daemons';

    /** @var list<string> */
    protected $guarded = [];

    /** Its own limits (the site environment's defaults apply on top). */
    public function resourceLimits(): ResourceLimits
    {
        return ResourceLimits::fromArray($this->limits);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'instances' => 'integer',
            'stop_timeout' => 'integer',
            'env' => SealedArray::class,
            'server_ids' => 'array',
            'limits' => 'array',
        ];
    }
}
