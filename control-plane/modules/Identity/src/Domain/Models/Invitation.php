<?php

namespace Kiln\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kiln\Identity\Contracts\Role;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property Role $role
 * @property string $token_hash
 * @property ?string $invited_by
 * @property Carbon $expires_at
 * @property ?Carbon $accepted_at
 */
class Invitation extends Model
{
    use HasUlids;

    protected $table = 'identity_invitations';

    /** @var list<string> */
    protected $fillable = ['organization_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
