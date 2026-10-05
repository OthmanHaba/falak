<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A server's Cloudflare Tunnel: cloudflared on the server connects out to Cloudflare, so the server needs no open
 * inbound ports. Names it serves get a proxied CNAME to {@see hostname()}.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $server_id
 * @property string $dns_credential_id
 * @property string $account_id
 * @property string $tunnel_id Cloudflare's tunnel id
 * @property string $name
 * @property string $token the tunnel's run token (encrypted)
 * @property string $status installing | active | error
 * @property ?string $error
 * @property ?string $command_id the last net.tunnel.apply
 * @property DnsCredential $credential
 */
class CloudflareTunnel extends Model
{
    use HasUlids;

    public const INSTALLING = 'installing';

    public const ACTIVE = 'active';

    public const ERROR = 'error';

    protected $table = 'edge_cloudflare_tunnels';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    /** @return BelongsTo<DnsCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(DnsCredential::class, 'dns_credential_id');
    }

    /** The CNAME target of names routed through this tunnel. */
    public function hostname(): string
    {
        return $this->tunnel_id.'.cfargotunnel.com';
    }
}
