<?php

namespace Falak\Providers\Domain\Models;

use Falak\Providers\Contracts\Data\CredentialSummary;
use Falak\Providers\Contracts\ProviderType;
use Falak\Providers\Database\Factories\ProviderCredentialFactory;
use Falak\Providers\Domain\CredentialStatus;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A cloud provider account for one organization. `credentials` is encrypted at rest and hidden
 * from serialization; it never leaves the Providers module.
 *
 * @property string $id
 * @property string $organization_id
 * @property ProviderType $provider
 * @property string $name
 * @property array<string, string> $credentials
 * @property CredentialStatus $status
 * @property ?Carbon $last_verified_at
 * @property ?string $last_error
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[UseFactory(ProviderCredentialFactory::class)]
class ProviderCredential extends Model
{
    /** @use HasFactory<ProviderCredentialFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'providers_credentials';

    /** @var list<string> */
    protected $fillable = ['organization_id', 'provider', 'name', 'credentials', 'status', 'last_verified_at', 'last_error', 'created_by'];

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
            'status' => CredentialStatus::class,
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForOrganization(Builder $query, string $organizationId): void
    {
        $query->where('organization_id', $organizationId);
    }

    public function toSummary(): CredentialSummary
    {
        return new CredentialSummary($this->id, $this->organization_id, $this->name, $this->provider);
    }
}
