<?php

namespace Falak\Fleet\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An issued agent client certificate.
 *
 * @property string $id
 * @property string $agent_id
 * @property string $serial
 * @property string $fingerprint SHA-256 of the DER certificate, lowercase hex
 * @property string $certificate_pem
 * @property Carbon $not_before
 * @property Carbon $not_after
 * @property ?Carbon $first_used_at
 * @property ?Carbon $superseded_at
 * @property ?Carbon $revoked_at
 */
class Certificate extends Model
{
    use HasUlids;

    protected $table = 'fleet_certificates';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'first_used_at' => 'datetime',
            'superseded_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->not_before->isPast() && $this->not_after->isFuture();
    }
}
