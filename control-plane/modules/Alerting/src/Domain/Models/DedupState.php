<?php

namespace Kiln\Alerting\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $organization_id
 * @property string $dedup_key
 * @property string $alert_id alert that opened the current (or last) episode
 * @property Carbon $first_alerted_at
 * @property Carbon $last_seen_at
 * @property int $occurrences
 * @property ?Carbon $resolved_at
 */
class DedupState extends Model
{
    public $timestamps = false;

    protected $table = 'alerting_dedup_states';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_alerted_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'occurrences' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->resolved_at === null;
    }
}
