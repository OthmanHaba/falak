<?php

namespace Falak\SourceControl\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Falak\SourceControl\Contracts\Data\WebhookData;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $connection_id
 * @property string $repository
 * @property ?string $provider_hook_id
 * @property string $secret
 * @property bool $installed
 * @property ?string $install_error
 * @property ?Carbon $last_delivery_at
 * @property-read Connection $connection
 */
class Webhook extends Model
{
    use HasUlids;

    protected $table = 'source_control_webhooks';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'installed' => 'boolean',
            'last_delivery_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function url(): string
    {
        $base = config('source_control.webhook_url') ?: config('app.url');

        return rtrim((string) $base, '/').'/api/webhooks/source-control/'.$this->id;
    }

    public function toData(): WebhookData
    {
        return new WebhookData(
            id: $this->id,
            connectionId: $this->connection_id,
            repository: $this->repository,
            url: $this->url(),
            installed: $this->installed,
            installError: $this->install_error,
        );
    }
}
