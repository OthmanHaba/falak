<?php

namespace Falak\Recovery\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One run of "This server is gone": the lost server's databases, volumes, services and domains brought back on the
 * target server, step by step.
 *
 * Steps, in order: replacement (the target is active), sites (their servers swapped, no deploy yet; edge routes and
 * managed DNS follow), databases (relocated, then restored), volumes (restored, attachments handed over), deploy
 * (every site redeployed on the restored data), domains (managed ones verified, others listed to change by hand).
 * A step's items each have a state: pending → running → succeeded | skipped | manual | failed.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $lost_server_id
 * @property string $lost_server_name
 * @property string $target_server_id
 * @property string $target_server_name
 * @property string $status running | failed | succeeded
 * @property ?string $current_step
 * @property list<array{key: string, title: string, status: string, message: ?string, items: list<array<string, mixed>>}> $steps
 * @property array<string, mixed> $plan
 * @property ?string $requested_by
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 */
class ServerRecovery extends Model
{
    use HasUlids;

    public const STEPS = [
        'replacement' => 'Replacement server',
        'sites' => 'Move services',
        'databases' => 'Restore databases',
        'volumes' => 'Restore volumes',
        'deploy' => 'Redeploy services',
        'domains' => 'Domains',
    ];

    /** Item states that let a step finish. */
    public const DONE = ['succeeded', 'skipped', 'manual'];

    protected $table = 'recovery_server_recoveries';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'plan' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /** @return array{key: string, title: string, status: string, message: ?string, items: list<array<string, mixed>>}|null */
    public function step(string $key): ?array
    {
        foreach ($this->steps as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        return null;
    }
}
