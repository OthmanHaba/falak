<?php

namespace Falak\Servers\Domain\Models;

use Falak\Servers\Domain\MachineCheck\MachineCheck;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The latest machine check of a server (one row per server).
 *
 * @property string $id
 * @property string $server_id
 * @property ?string $command_id the provision.inspect command (the running one while status is running)
 * @property string $purpose provision (apply when nothing blocks) or check (report only)
 * @property string $status running, finished or failed
 * @property ?array<string, mixed> $report provision.inspect $defs.result of the last finished check
 * @property ?list<array<string, mixed>> $decisions
 * @property bool $blocking
 * @property ?string $agent_version
 * @property ?string $error
 * @property ?Carbon $checked_at when the report was taken
 * @property Carbon $created_at
 */
class MachineInspection extends Model
{
    use HasUlids;

    public const PURPOSE_PROVISION = 'provision';

    public const PURPOSE_CHECK = 'check';

    public const RUNNING = 'running';

    public const FINISHED = 'finished';

    public const FAILED = 'failed';

    protected $table = 'servers_machine_inspections';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report' => 'array',
            'decisions' => 'array',
            'blocking' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    public function check(): ?MachineCheck
    {
        return $this->decisions !== null ? MachineCheck::fromArray($this->decisions) : null;
    }
}
