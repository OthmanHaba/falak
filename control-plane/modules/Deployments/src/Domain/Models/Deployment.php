<?php

namespace Kiln\Deployments\Domain\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\Strategy;
use Kiln\Deployments\Domain\Enums\Trigger;

/**
 * One deployment of a site: a persisted state machine driven by its steps.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $site_slug
 * @property int $number
 * @property Trigger $trigger
 * @property DeploymentStatus $status
 * @property ?string $phase
 * @property ?Strategy $strategy
 * @property ?string $branch
 * @property ?string $commit
 * @property ?string $commit_message
 * @property ?string $commit_author
 * @property ?string $build_id
 * @property ?string $release_id
 * @property ?string $target_release_id
 * @property ?string $previous_release_id
 * @property bool $rolling_back
 * @property bool $rolled_back
 * @property bool $cancel_requested
 * @property ?array<string, string> $variables
 * @property ?array<string, mixed> $settings
 * @property ?string $error
 * @property ?string $requested_by
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 * @property Collection<int, DeploymentTarget> $targets
 * @property Collection<int, DeploymentStep> $steps
 */
class Deployment extends Model
{
    use HasUlids;

    protected $table = 'deployments_deployments';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['variables'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => Trigger::class,
            'status' => DeploymentStatus::class,
            'strategy' => Strategy::class,
            'number' => 'integer',
            'rolling_back' => 'boolean',
            'rolled_back' => 'boolean',
            'cancel_requested' => 'boolean',
            // Deploy hook KILN_VAR_* values may carry secrets.
            'variables' => 'encrypted:array',
            'settings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<DeploymentTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(DeploymentTarget::class)->orderBy('position');
    }

    /**
     * @return HasMany<DeploymentStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(DeploymentStep::class)->orderBy('position');
    }

    /**
     * @return HasMany<OutputLine, $this>
     */
    public function output(): HasMany
    {
        return $this->hasMany(OutputLine::class)->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function url(): string
    {
        return rtrim((string) config('app.url'), '/')."/sites/{$this->site_id}/deployments/{$this->id}";
    }

    public function shortCommit(): ?string
    {
        return $this->commit !== null ? substr($this->commit, 0, 7) : null;
    }
}
