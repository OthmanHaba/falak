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
 * Linked secrets may also carry a `snapshot`: the value resolved when the version was made (a change seen
 * upstream by the watch), sealed the same way under its own AAD. A rollback to such a version copies the
 * snapshot, and the restored version is pinned to it: deployments use the snapshot instead of asking the
 * provider until a new reference is saved.
 *
 * @property string $id
 * @property string $secret_id
 * @property int $version
 * @property string $ciphertext
 * @property ?string $snapshot
 * @property ?string $note
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
    protected $hidden = ['ciphertext', 'snapshot'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'restored_from' => 'integer', 'disabled_at' => 'datetime'];
    }

    /** A restored linked version with a snapshot: deployments use the snapshot, not the provider. */
    public function pinned(): bool
    {
        return $this->restored_from !== null && $this->snapshot !== null;
    }

    /**
     * @return BelongsTo<Secret, $this>
     */
    public function secret(): BelongsTo
    {
        return $this->belongsTo(Secret::class);
    }
}
