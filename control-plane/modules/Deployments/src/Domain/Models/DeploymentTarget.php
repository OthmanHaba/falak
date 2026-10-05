<?php

namespace Falak\Deployments\Domain\Models;

use Falak\Deployments\Domain\Enums\TargetStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $deployment_id
 * @property string $server_id
 * @property string $server_name
 * @property string $role leader|member
 * @property int $batch
 * @property int $position
 * @property TargetStatus $status
 * @property bool $activated
 * @property ?string $previous_release_id
 * @property ?string $error
 */
class DeploymentTarget extends Model
{
    use HasUlids;

    protected $table = 'deployments_targets';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => TargetStatus::class, 'batch' => 'integer', 'position' => 'integer', 'activated' => 'boolean'];
    }

    public function isLeader(): bool
    {
        return $this->role === 'leader';
    }
}
