<?php

namespace Falak\Deployments\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Falak\Deployments\Domain\Enums\StepKind;
use Falak\Deployments\Domain\Enums\StepStatus;

/**
 * One node of a deployment's plan (a DAG): a build, an agent command on one server, or a health
 * check. `depends_on` lists step keys that must succeed (or be skipped) first.
 *
 * @property string $id
 * @property string $deployment_id
 * @property ?string $target_id
 * @property ?string $server_id
 * @property string $key
 * @property StepKind $kind
 * @property string $phase build|fetch|prepare|migrate|activate|restart|healthcheck|rollback
 * @property bool $rollback
 * @property int $batch
 * @property int $position
 * @property list<string> $depends_on
 * @property ?array<string, mixed> $meta
 * @property StepStatus $status
 * @property ?string $command_type
 * @property ?string $command_id
 * @property ?string $build_id
 * @property ?string $idempotency_key
 * @property int $attempts
 * @property ?int $exit_code
 * @property ?array<string, mixed> $result
 * @property ?string $error
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?DeploymentTarget $target
 */
class DeploymentStep extends Model
{
    use HasUlids;

    protected $table = 'deployments_steps';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => StepKind::class,
            'status' => StepStatus::class,
            'rollback' => 'boolean',
            'batch' => 'integer',
            'position' => 'integer',
            'depends_on' => 'array',
            'meta' => 'array',
            'attempts' => 'integer',
            'exit_code' => 'integer',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DeploymentTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(DeploymentTarget::class, 'target_id');
    }

    public function label(): string
    {
        return match ($this->kind) {
            StepKind::Hook => 'script: '.($this->meta['name'] ?? 'hook'),
            StepKind::HealthCheck => 'health check',
            StepKind::RevertSwap => 'revert container',
            StepKind::RevertRestart => 'restart processes',
            default => str_replace('_', ' ', $this->kind->value),
        };
    }
}
