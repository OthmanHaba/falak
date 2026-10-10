<?php

namespace Falak\Fleet\Domain\Models;

use Falak\Fleet\Contracts\Data\MetricSample;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $agent_id
 * @property ?string $server_id
 * @property Carbon $at
 * @property int $uptime_s
 * @property float $load1
 * @property float $load5
 * @property float $load15
 * @property ?float $cpu_percent
 * @property int $memory_used_bytes
 * @property int $disk_used_bytes
 * @property ?array<string, array{0: int, 1: int, 2: int}> $disks mount => [used, available, total] bytes
 */
class AgentMetric extends Model
{
    public $timestamps = false;

    protected $table = 'fleet_agent_metrics';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['at' => 'datetime', 'load1' => 'float', 'load5' => 'float', 'load15' => 'float', 'cpu_percent' => 'float', 'disks' => 'array'];
    }

    public function toSample(): MetricSample
    {
        return new MetricSample(
            $this->at->toDateTimeImmutable(),
            $this->load1,
            $this->load5,
            $this->load15,
            $this->cpu_percent,
            (int) $this->memory_used_bytes,
            (int) $this->disk_used_bytes,
            $this->disks ?? [],
        );
    }
}
