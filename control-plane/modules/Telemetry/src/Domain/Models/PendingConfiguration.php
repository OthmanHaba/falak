<?php

namespace Kiln\Telemetry\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $server_id
 * @property string $organization_id
 * @property Carbon $due_at
 * @property int $attempts
 */
class PendingConfiguration extends Model
{
    protected $table = 'telemetry_pending_configs';

    protected $primaryKey = 'server_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'attempts' => 'integer'];
    }
}
