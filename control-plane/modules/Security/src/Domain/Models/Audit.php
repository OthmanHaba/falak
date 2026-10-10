<?php

namespace Falak\Security\Domain\Models;

use Falak\Security\Domain\Enums\AuditStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One security.audit run of a server and its score.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $server_id
 * @property AuditStatus $status
 * @property string $trigger scheduled|manual|provisioned|fix
 * @property ?string $command_id
 * @property ?int $score 0-100 (see Score)
 * @property ?array<string, int> $counts
 * @property bool $production_ready
 * @property ?int $duration_ms
 * @property ?string $error
 * @property ?string $requested_by
 * @property ?Carbon $ran_at
 * @property Carbon $created_at
 * @property-read Collection<int, Finding> $findings
 */
class Audit extends Model
{
    use HasUlids;

    protected $table = 'security_audits';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AuditStatus::class,
            'score' => 'integer',
            'counts' => 'array',
            'production_ready' => 'boolean',
            'duration_ms' => 'integer',
            'ran_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'audit_id');
    }
}
