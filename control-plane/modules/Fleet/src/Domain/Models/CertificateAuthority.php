<?php

namespace Falak\Fleet\Domain\Models;

use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $certificate_pem
 * @property string $private_key PEM (encrypted at rest)
 * @property string $fingerprint
 * @property Carbon $not_before
 * @property Carbon $not_after
 * @property bool $active
 */
class CertificateAuthority extends Model
{
    use HasUlids;

    protected $table = 'fleet_certificate_authorities';

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
            'private_key' => Sealed::class,
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'active' => 'boolean',
        ];
    }
}
