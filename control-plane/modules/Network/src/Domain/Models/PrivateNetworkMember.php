<?php

namespace Falak\Network\Domain\Models;

use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Enums\KeyStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $network_id
 * @property string $organization_id
 * @property string $server_id
 * @property string $address bare IPv4 inside the network CIDR
 * @property string $public_key base64 X25519 public key
 * @property ?string $private_key base64 private key; only held until it is installed on the host
 * @property KeyStatus $key_status
 * @property ?string $key_command_id
 * @property ApplyStatus $status
 * @property ?string $command_id
 * @property ?string $desired_hash
 * @property ?string $applied_hash
 * @property int $revision
 * @property ?string $error
 * @property ?Carbon $applied_at
 * @property Carbon $created_at
 * @property PrivateNetwork $network
 */
class PrivateNetworkMember extends Model
{
    use HasUlids;

    protected $table = 'network_private_network_members';

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
            'key_status' => KeyStatus::class,
            'status' => ApplyStatus::class,
            'revision' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PrivateNetwork, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(PrivateNetwork::class, 'network_id');
    }
}
