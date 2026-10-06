<?php

namespace Falak\Secrets\Domain\Models;

use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Enums\SecretKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named secret in one scope. Its values are the immutable {@see SecretVersion}s; `current_version` is the one
 * deployments use. Sensitive secrets are write-only: once saved, no one can read the value back.
 *
 * @property string $id
 * @property string $organization_id
 * @property SecretScope $scope_type
 * @property string $scope_id
 * @property string $name
 * @property SecretKind $kind
 * @property ?string $provider_id
 * @property bool $sensitive
 * @property bool $available_to_previews
 * @property ?string $description
 * @property ?int $rotation_days
 * @property int $current_version
 * @property ?Carbon $last_accessed_at
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, SecretVersion> $versions
 */
class Secret extends Model
{
    use HasUlids;

    protected $table = 'secrets_secrets';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => SecretScope::class,
            'kind' => SecretKind::class,
            'sensitive' => 'boolean',
            'available_to_previews' => 'boolean',
            'rotation_days' => 'integer',
            'current_version' => 'integer',
            'last_accessed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SecretVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SecretVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): ?SecretVersion
    {
        return SecretVersion::query()->where('secret_id', $this->id)->where('version', $this->current_version)->first();
    }

    /** When the rotation policy says the value is due for a change (null: no policy). */
    public function rotationDueAt(): ?Carbon
    {
        if ($this->rotation_days === null) {
            return null;
        }

        $since = SecretVersion::query()->where('secret_id', $this->id)->where('version', $this->current_version)->value('created_at');

        return Carbon::parse($since ?? $this->created_at)->addDays($this->rotation_days);
    }
}
