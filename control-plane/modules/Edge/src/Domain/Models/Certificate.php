<?php

namespace Kiln\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An uploaded TLS certificate, installed on servers with edge.cert.install as /etc/kiln/certs/<name>.{crt,key}.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $site_id
 * @property string $name
 * @property list<string> $domains
 * @property string $cert_pem
 * @property string $key_pem
 * @property ?string $chain_pem
 * @property ?string $issuer
 * @property ?Carbon $not_before
 * @property ?Carbon $not_after
 * @property string $fingerprint
 * @property ?string $created_by
 * @property Carbon $created_at
 */
class Certificate extends Model
{
    use HasUlids;

    protected $table = 'edge_certificates';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['key_pem'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'key_pem' => 'encrypted',
            'not_before' => 'datetime',
            'not_after' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CertificateInstall, $this>
     */
    public function installs(): HasMany
    {
        return $this->hasMany(CertificateInstall::class);
    }

    /** Whether the certificate covers a host (exact or single-label wildcard match). */
    public function covers(string $host): bool
    {
        foreach ($this->domains as $name) {
            if ($name === $host) {
                return true;
            }

            if (str_starts_with($name, '*.') && ! str_starts_with($host, '*.')) {
                $suffix = substr($name, 1);

                if (str_ends_with($host, $suffix) && ! str_contains(substr($host, 0, -strlen($suffix)), '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isExpired(): bool
    {
        return $this->not_after !== null && $this->not_after->isPast();
    }
}
