<?php

namespace Falak\SourceControl\Domain\Models;

use Falak\SourceControl\Contracts\Data\DeployKeyData;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $connection_id
 * @property string $repository
 * @property string $title
 * @property string $public_key
 * @property string $private_key
 * @property string $fingerprint
 * @property ?string $provider_key_id
 * @property ?Carbon $installed_at
 * @property ?string $install_error
 * @property-read Connection $connection
 */
class DeployKey extends Model
{
    use HasUlids;

    protected $table = 'source_control_deploy_keys';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['private_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'installed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function toData(): DeployKeyData
    {
        return new DeployKeyData(
            id: $this->id,
            connectionId: $this->connection_id,
            repository: $this->repository,
            publicKey: $this->public_key,
            fingerprint: $this->fingerprint,
            installed: $this->installed_at !== null,
            installError: $this->install_error,
        );
    }
}
