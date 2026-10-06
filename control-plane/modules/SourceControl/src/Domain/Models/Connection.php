<?php

namespace Falak\SourceControl\Domain\Models;

use Falak\Kernel\Security\Casts\SealedArray;
use Falak\SourceControl\Contracts\Data\ConnectionData;
use Falak\SourceControl\Contracts\ProviderType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property string $status active | suspended | disconnected (GitHub App installations suspended / removed on GitHub)
 * @property ?string $github_app_id GitHub App connections: "env" or a source_control_github_apps id
 * @property ?string $installation_id GitHub App connections: the installation id
 * @property Carbon $created_at
 */
class Connection extends Model
{
    use HasUlids;

    public const AUTH_TYPES = ['oauth', 'app', 'token', 'basic', 'none'];

    public const STATUSES = ['active', 'suspended', 'disconnected'];

    protected $table = 'source_control_connections';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['credentials'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => ProviderType::class,
            'credentials' => SealedArray::class,
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

    public function isApp(): bool
    {
        return $this->auth_type === 'app';
    }

    public function installationId(): string
    {
        return (string) ($this->installation_id ?: $this->credential('installation_id'));
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
            status: $this->status ?? 'active',
        );
    }
}
