<?php

namespace Falak\Kernel\Security;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A data key, wrapped by the KEK. The active key for a purpose is its newest one that isn't retired;
 * retired keys still open what they sealed until falak:keys:rotate-data has re-encrypted it.
 *
 * @property string $id
 * @property string $purpose 'platform' | 'org:<organization id>'
 * @property string $wrapped_key
 * @property string $kek_provider
 * @property string $kek_id
 * @property Carbon|null $created_at
 * @property Carbon|null $retired_at
 */
final class DataKey extends Model
{
    use HasUlids;

    public const PLATFORM = 'platform';

    public const UPDATED_AT = null;

    protected $table = 'kernel_data_keys';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['wrapped_key'];

    public static function organization(string $organizationId): string
    {
        return "org:{$organizationId}";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    /**
     * What the KEK binds the wrapped key to: a wrapped key moved to another row or purpose won't unwrap.
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['falak:data-key' => $this->id, 'falak:purpose' => $this->purpose];
    }
}
