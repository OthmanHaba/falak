<?php

namespace Falak\Recipes\Domain\Models;

use Falak\Recipes\Domain\Enums\TargetStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The run of a recipe on one server (one system.exec command).
 *
 * @property string $id
 * @property string $run_id
 * @property string $organization_id
 * @property string $server_id
 * @property string $server_name
 * @property ?string $command_id
 * @property TargetStatus $status
 * @property ?int $exit_code
 * @property ?string $error
 * @property ?int $duration_ms
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 */
class RunTarget extends Model
{
    use HasUlids;

    protected $table = 'recipes_run_targets';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
