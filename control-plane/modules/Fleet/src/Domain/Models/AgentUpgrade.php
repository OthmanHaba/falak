<?php

namespace Falak\Fleet\Domain\Models;

use Falak\Fleet\Contracts\AgentUpgradeStatus;
use Falak\Fleet\Contracts\Data\AgentUpgradeData;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $agent_id
 * @property string $server_id
 * @property ?string $rollout_id
 * @property AgentUpgradeStatus $status
 * @property string $arch
 * @property ?string $from_version
 * @property string $to_version
 * @property string $sha256
 * @property ?string $command_id
 * @property bool $installed the agent swapped its binary (command finished); waiting for it to report the build
 * @property ?string $error
 * @property ?string $requested_by
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 */
class AgentUpgrade extends Model
{
    use HasUlids;

    protected $table = 'fleet_agent_upgrades';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentUpgradeStatus::class,
            'installed' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function toData(): AgentUpgradeData
    {
        return new AgentUpgradeData($this->id, $this->server_id, $this->status, $this->from_version, $this->to_version, $this->rollout_id,
            $this->error, $this->created_at->toDateTimeImmutable(), $this->finished_at?->toDateTimeImmutable());
    }
}
