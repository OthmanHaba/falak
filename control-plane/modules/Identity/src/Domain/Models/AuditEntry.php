<?php

namespace Kiln\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only audit log entry.
 *
 * @property string $id
 * @property ?string $organization_id
 * @property string $actor_type
 * @property ?string $actor_id
 * @property ?string $actor_name
 * @property string $action
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property array<string, mixed> $context
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property Carbon $created_at
 */
class AuditEntry extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'identity_audit_log';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }
}
