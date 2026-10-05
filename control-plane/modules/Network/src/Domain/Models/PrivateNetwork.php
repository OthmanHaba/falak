<?php

namespace Falak\Network\Domain\Models;

use Falak\Network\Domain\Support\Ipv4Cidr;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A WireGuard full mesh between servers of one organization.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $cidr
 * @property string $interface
 * @property int $listen_port
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PrivateNetwork extends Model
{
    use HasUlids;

    protected $table = 'network_private_networks';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['listen_port' => 'integer'];
    }

    /**
     * @return HasMany<PrivateNetworkMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(PrivateNetworkMember::class, 'network_id')->orderBy('created_at')->orderBy('id');
    }

    public function range(): Ipv4Cidr
    {
        return Ipv4Cidr::parse($this->cidr);
    }
}
