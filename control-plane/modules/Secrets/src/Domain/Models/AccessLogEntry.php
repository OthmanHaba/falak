<?php

namespace Falak\Secrets\Domain\Models;

use Falak\Secrets\Contracts\AccessorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One read of a secret version: by whom or what (a user's reveal, a deployment, an API token), and why.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $secret_id
 * @property string $secret_name
 * @property int $version
 * @property AccessorType $actor_type
 * @property ?string $actor_id
 * @property ?string $user_id
 * @property string $reason
 * @property ?string $ip
 * @property Carbon $created_at
 */
class AccessLogEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'secrets_access_log';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['actor_type' => AccessorType::class, 'version' => 'integer'];
    }
}
