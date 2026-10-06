<?php

namespace Falak\Secrets\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The last good value of one reference at one provider, sealed under the organization's data key and bound to
 * (organization, provider, reference). Read and written through the provider value cache only.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $provider_id
 * @property string $reference_hash
 * @property string $ciphertext
 * @property Carbon $fetched_at
 */
class ProviderValue extends Model
{
    public $timestamps = false;

    protected $table = 'secrets_provider_values';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['ciphertext'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }
}
