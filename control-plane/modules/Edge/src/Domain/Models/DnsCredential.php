<?php

namespace Falak\Edge\Domain\Models;

use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * DNS provider API credential for ACME DNS-01 challenges (wildcard certificates).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $provider
 * @property string $name
 * @property string $api_token
 * @property ?string $account_id Cloudflare account of the token's zones (tunnels are account-level)
 * @property ?Carbon $verified_at
 * @property ?string $created_by
 */
class DnsCredential extends Model
{
    use HasUlids;

    public const PROVIDERS = ['cloudflare' => 'Cloudflare'];

    protected $table = 'edge_dns_credentials';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['api_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['api_token' => Sealed::class, 'verified_at' => 'datetime'];
    }
}
