<?php

namespace Falak\Alerting\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Falak\Alerting\Contracts\Severity;

/**
 * In-app notification (notification center).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property ?string $alert_id
 * @property string $type
 * @property Severity $severity
 * @property string $title
 * @property ?string $body
 * @property ?string $url
 * @property ?Carbon $read_at
 * @property Carbon $created_at
 */
class Notification extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'alerting_notifications';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
