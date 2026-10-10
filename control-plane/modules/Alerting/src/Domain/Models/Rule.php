<?php

namespace Falak\Alerting\Domain\Models;

use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\QuietHours;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property ?string $pack_key the default rule pack's area this rule was created for (DefaultRulePack); null: user rule
 * @property string $name
 * @property list<string> $event_types exact types, "group.*" prefixes or "*"
 * @property Severity $min_severity
 * @property bool $enabled
 * @property ?array{start: string, end: string, timezone: string, days?: list<int>|null, allow_critical?: bool} $quiet_hours
 * @property ?int $rate_limit_per_hour
 * @property Carbon $created_at
 */
class Rule extends Model
{
    use HasUlids;

    protected $table = 'alerting_rules';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'min_severity' => Severity::class,
            'enabled' => 'boolean',
            'quiet_hours' => 'array',
            'rate_limit_per_hour' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Channel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'alerting_rule_channels', 'rule_id', 'channel_id');
    }

    public function matches(string $type, Severity $severity): bool
    {
        if (! $severity->atLeast($this->min_severity)) {
            return false;
        }

        foreach ($this->event_types as $pattern) {
            if ($pattern === '*' || $pattern === $type) {
                return true;
            }

            if (str_ends_with($pattern, '.*') && str_starts_with($type, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    public function quietHours(): ?QuietHours
    {
        return $this->quiet_hours ? QuietHours::fromArray($this->quiet_hours) : null;
    }
}
