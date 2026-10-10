<?php

namespace Falak\Deployments\Domain\Models;

use Falak\Deployments\Domain\Enums\WatchStatus;
use Falak\Deployments\Domain\Enums\WatchTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The watch window after a deployment's release went live (one per deployment): its triggers are evaluated until
 * `ends_at`, and the first one that fires rolls the site back to the previous release (or only alerts).
 *
 * @property string $deployment_id
 * @property string $organization_id
 * @property string $site_id
 * @property string $release_id
 * @property ?string $previous_release_id
 * @property WatchStatus $status
 * @property array{health: bool, health_failures: int, crashes: bool, errors: bool, issues: bool} $triggers
 * @property string $on_trigger rollback | alert_only
 * @property bool $migrations the deployment ran database migrations (a rollback doesn't reverse them)
 * @property ?array{total: int, errors: int, rate: float} $baseline the previous release's last hour (null: no data)
 * @property ?array<string, mixed> $checks live status of each trigger
 * @property int $health_failures failed health checks in a row
 * @property ?WatchTrigger $trigger what fired
 * @property ?string $reason
 * @property ?string $rollback_deployment_id
 * @property Carbon $started_at
 * @property Carbon $ends_at
 * @property ?Carbon $checked_at
 * @property ?Carbon $finished_at
 */
class ReleaseWatch extends Model
{
    public const ROLLBACK = 'rollback';

    public const ALERT_ONLY = 'alert_only';

    protected $table = 'deployments_release_watches';

    protected $primaryKey = 'deployment_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WatchStatus::class,
            'trigger' => WatchTrigger::class,
            'triggers' => 'array',
            'migrations' => 'boolean',
            'baseline' => 'array',
            'checks' => 'array',
            'health_failures' => 'integer',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'checked_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Shape in deployment resources (`watch`).
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'status' => $this->status->value,
            'on_trigger' => $this->on_trigger,
            'triggers' => $this->triggers,
            'migrations' => $this->migrations,
            'started_at' => $this->started_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'remaining_s' => $this->status === WatchStatus::Watching ? max(0, (int) now()->diffInSeconds($this->ends_at, false)) : 0,
            'checked_at' => $this->checked_at?->toIso8601String(),
            'checks' => $this->checks ?? (object) [],
            'baseline' => $this->baseline,
            'trigger' => $this->trigger?->value,
            'reason' => $this->reason,
            'rollback_deployment_id' => $this->rollback_deployment_id,
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
