<?php

namespace Kiln\Deployments\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $command_id
 * @property string $step_id
 * @property string $deployment_id
 * @property string $type
 * @property ?string $status null while pending, then succeeded|failed
 */
class StepCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'command_id';

    protected $table = 'deployments_step_commands';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return BelongsTo<DeploymentStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(DeploymentStep::class);
    }
}
