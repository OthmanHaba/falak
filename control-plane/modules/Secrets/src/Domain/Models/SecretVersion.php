<?php

namespace Falak\Secrets\Domain\Models;

use Falak\Secrets\Application\SecretCipher;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One immutable value of a secret (for a linked secret: its reference). The ciphertext is sealed under the
 * organization's data key with the AAD Sealer::aad('secret', organization, secret, version), so it opens only
 * as that version of that secret. Read it through {@see SecretCipher}.
 *
 * @property string $id
 * @property string $secret_id
 * @property int $version
 * @property string $ciphertext
 * @property ?int $restored_from
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property ?Carbon $disabled_at
 * @property-read Secret $secret
 */
class SecretVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'secrets_versions';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['ciphertext'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'restored_from' => 'integer', 'disabled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Secret, $this>
     */
    public function secret(): BelongsTo
    {
        return $this->belongsTo(Secret::class);
    }
}
