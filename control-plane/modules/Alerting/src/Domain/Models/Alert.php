<?php

namespace Falak\Alerting\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Enums\AlertOutcome;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $type
 * @property Severity $severity
 * @property string $title
 * @property ?string $body
 * @property ?string $url
 * @property ?string $dedup_key
 * @property bool $recovery
 * @property ?array<string, scalar|null> $context
 * @property AlertOutcome $outcome
 * @property ?list<string> $matched_rule_ids
 * @property Carbon $created_at
 */
class Alert extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'alerting_alerts';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'outcome' => AlertOutcome::class,
            'recovery' => 'boolean',
            'context' => 'array',
            'matched_rule_ids' => 'array',
        ];
    }

    /**
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
