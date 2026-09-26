<?php

namespace Kiln\Processes\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kiln\Processes\Domain\Enums\ApplyStatus;

/**
 * What Processes last sent to (and heard back from) one server.
 *
 * `programs` / `jobs` describe the desired set last dispatched (name => metadata); `applied_*` the names
 * the agent confirmed. `process_status` is the last proc.status result.
 *
 * @property string $server_id
 * @property string $organization_id
 * @property ?string $proc_sha256
 * @property ?string $proc_command_id
 * @property ?ApplyStatus $proc_status
 * @property ?string $proc_error
 * @property ?array<string, array{site_id: string, kind: string, label: string, numprocs: int}> $programs
 * @property ?list<string> $applied_programs
 * @property ?Carbon $proc_dispatched_at
 * @property ?Carbon $proc_applied_at
 * @property ?string $cron_sha256
 * @property ?string $cron_command_id
 * @property ?ApplyStatus $cron_status
 * @property ?string $cron_error
 * @property ?array<string, array{site_id: string, kind: string, label: string, schedule: string, timezone: string, heartbeat: bool}> $jobs
 * @property ?list<string> $applied_jobs
 * @property ?Carbon $cron_dispatched_at
 * @property ?Carbon $cron_applied_at
 * @property ?list<array<string, mixed>> $process_status
 * @property ?Carbon $status_at
 * @property ?list<string> $crash_looping
 */
class ServerState extends Model
{
    protected $table = 'processes_server_states';

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
        return [
            'proc_status' => ApplyStatus::class,
            'cron_status' => ApplyStatus::class,
            'programs' => 'array',
            'applied_programs' => 'array',
            'jobs' => 'array',
            'applied_jobs' => 'array',
            'process_status' => 'array',
            'crash_looping' => 'array',
            'proc_dispatched_at' => 'datetime',
            'proc_applied_at' => 'datetime',
            'cron_dispatched_at' => 'datetime',
            'cron_applied_at' => 'datetime',
            'status_at' => 'datetime',
        ];
    }
}
