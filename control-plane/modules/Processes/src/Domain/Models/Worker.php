<?php

namespace Falak\Processes\Domain\Models;

use Falak\Kernel\Security\Casts\SealedArray;
use Falak\Limits\Contracts\ResourceLimits;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A queue worker of a site: `php artisan queue:work` for PHP sites, a custom start command otherwise.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property ?string $connection
 * @property ?string $queue comma-separated queue names
 * @property ?string $command custom start command (non-PHP sites)
 * @property int $processes
 * @property int $timeout
 * @property int $sleep
 * @property int $tries
 * @property ?int $backoff
 * @property ?int $max_jobs
 * @property ?int $max_time
 * @property int $memory
 * @property array<string, string> $env encrypted
 * @property ?array<string, mixed> $limits resource limits (Limits' ResourceLimits JSON) of its slice
 * @property ?list<string> $server_ids
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Worker extends Model
{
    use HasUlids;
    use ServerTargets;

    protected $table = 'processes_workers';

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
            'processes' => 'integer',
            'timeout' => 'integer',
            'sleep' => 'integer',
            'tries' => 'integer',
            'backoff' => 'integer',
            'max_jobs' => 'integer',
            'max_time' => 'integer',
            'memory' => 'integer',
            'env' => SealedArray::class,
            'server_ids' => 'array',
            'limits' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public function queues(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->queue)), fn (string $q) => $q !== ''));
    }
}
