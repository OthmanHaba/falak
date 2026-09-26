<?php

namespace Kiln\SourceControl\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kiln\SourceControl\Contracts\Data\ConnectionData;
use Kiln\SourceControl\Contracts\ProviderType;

/**
 * @property string $id
 * @property string $organization_id
 * @property ProviderType $provider
 * @property string $name
 * @property string $auth_type oauth | app | token | basic | none
 * @property ?string $base_url
 * @property ?string $account
 * @property array<string, mixed> $credentials access_token, refresh_token, expires_at | installation_id | token | username, password
 * @property ?string $created_by
 * @property Carbon $created_at
 */
class Connection extends Model
{
    use HasUlids;

    public const AUTH_TYPES = ['oauth', 'app', 'token', 'basic', 'none'];

    protected $table = 'source_control_connections';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => ProviderType::class,
            'credentials' => 'encrypted:array',
        ];
    }

    /**
     * @return HasMany<DeployKey, $this>
     */
    public function deployKeys(): HasMany
    {
        return $this->hasMany(DeployKey::class);
    }

    /**
     * @return HasMany<Webhook, $this>
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function credential(string $key): mixed
    {
        return ($this->credentials ?? [])[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function mergeCredentials(array $values): void
    {
        $this->credentials = array_merge($this->credentials ?? [], $values);
        $this->save();
    }

    public function toData(): ConnectionData
    {
        return new ConnectionData(
            id: $this->id,
            organizationId: $this->organization_id,
            provider: $this->provider,
            name: $this->name,
            authType: $this->auth_type,
            account: $this->account,
            baseUrl: $this->base_url,
        );
    }
}
