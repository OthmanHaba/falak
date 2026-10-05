<?php

namespace Falak\Insights\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Falak\Insights\Domain\Enums\MonitoredEventType;
use Falak\Insights\Domain\Enums\ThresholdMetric;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property MonitoredEventType $event_type
 * @property ?string $name_pattern
 * @property ThresholdMetric $metric
 * @property float $threshold_ms
 * @property int $window_minutes
 * @property int $min_count
 * @property bool $enabled
 * @property ?Carbon $last_evaluated_at
 * @property ?Carbon $last_breached_at
 */
class Threshold extends Model
{
    use HasUlids;

    protected $table = 'insights_thresholds';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['min_count' => 1, 'enabled' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => MonitoredEventType::class,
            'metric' => ThresholdMetric::class,
            'threshold_ms' => 'float',
            'window_minutes' => 'integer',
            'min_count' => 'integer',
            'enabled' => 'boolean',
            'last_evaluated_at' => 'datetime',
            'last_breached_at' => 'datetime',
        ];
    }

    /**
     * SQL LIKE pattern for name_pattern ("*" wildcard; everything else literal), or null for "any".
     */
    public function likePattern(): ?string
    {
        if ($this->name_pattern === null || $this->name_pattern === '' || $this->name_pattern === '*') {
            return null;
        }

        return str_replace('*', '%', addcslashes($this->name_pattern, '\\%_'));
    }

    public function describe(): string
    {
        return sprintf('%s over %d min above %s ms', $this->metric->label(), $this->window_minutes, rtrim(rtrim(number_format($this->threshold_ms, 2, '.', ''), '0'), '.'));
    }
}
