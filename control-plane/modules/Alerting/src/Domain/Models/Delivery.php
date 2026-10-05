<?php

namespace Falak\Alerting\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Falak\Alerting\Domain\Enums\DeliveryStatus;

/**
 * @property string $id
 * @property string $alert_id
 * @property string $channel_id
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property ?string $error
 * @property ?Carbon $sent_at
 * @property-read Alert $alert
 * @property-read Channel $channel
 */
class Delivery extends Model
{
    use HasUlids;

    protected $table = 'alerting_deliveries';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Alert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
