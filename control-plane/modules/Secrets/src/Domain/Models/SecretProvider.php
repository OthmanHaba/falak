<?php

namespace Falak\Secrets\Domain\Models;

use Falak\Kernel\Security\Casts\SealedArray;
use Falak\Secrets\Domain\Enums\ProviderStatus;
use Falak\Secrets\Domain\Enums\ProviderType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An organization's external secret provider (Vault, AWS, 1Password Connect, Doppler, Infisical, an HTTPS
 * webhook). Its config (endpoint and credentials) is sealed and never leaves the control plane; `last_error`
 * names the problem only, never a credential or a value.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property ProviderType $type
 * @property array<string, mixed> $config
 * @property int $config_version
 * @property bool $allow_private_network
 * @property int $cache_ttl_seconds
 * @property ProviderStatus $status
 * @property ?Carbon $last_checked_at
 * @property ?string $last_error
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SecretProvider extends Model
{
    use HasUlids;

    protected $table = 'secrets_providers';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['config'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'config' => SealedArray::class,
            'config_version' => 'integer',
            'allow_private_network' => 'boolean',
            'cache_ttl_seconds' => 'integer',
            'status' => ProviderStatus::class,
            'last_checked_at' => 'datetime',
        ];
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
