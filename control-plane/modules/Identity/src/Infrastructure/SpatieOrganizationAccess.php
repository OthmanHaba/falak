<?php

namespace Falak\Identity\Infrastructure;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\PersonalAccessToken;
use Falak\Identity\Domain\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\PermissionRegistrar;

/**
 * Organization-scoped authorization on top of spatie/laravel-permission "teams".
 * Results are memoized per request (scoped binding); call {@see flush()} after role changes.
 */
final class SpatieOrganizationAccess implements OrganizationAccess
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function can(?Authenticatable $user, string $organizationId, string $permission): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            if ($token->organization_id !== $organizationId || ! $token->can($permission)) {
                return false;
            }
        }

        return $this->memo["{$user->id}|{$organizationId}|{$permission}"] ??= $this->check($user, $organizationId, $permission);
    }

    public function authorize(?Authenticatable $user, string $organizationId, string $permission): void
    {
        if (! $this->can($user, $organizationId, $permission)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    public function isMember(string $userId, string $organizationId): bool
    {
        return DB::table('identity_memberships')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function roleOf(string $userId, string $organizationId): ?Role
    {
        $name = DB::table('identity_model_has_roles')
            ->join('identity_roles', 'identity_roles.id', '=', 'identity_model_has_roles.role_id')
            ->where('identity_model_has_roles.model_type', (new User)->getMorphClass())
            ->where('identity_model_has_roles.model_id', $userId)
            ->where('identity_model_has_roles.organization_id', $organizationId)
            ->pluck('identity_roles.name')
            ->map(fn (string $role) => Role::tryFrom($role))
            ->filter()
            ->sortByDesc(fn (Role $role) => $role->rank())
            ->first();

        return $name instanceof Role ? $name : null;
    }

    public function memberIds(string $organizationId): array
    {
        return DB::table('identity_memberships')
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * @return list<string>
     */
    public function permissionsOf(User $user, string $organizationId): array
    {
        return $this->asOrganization($user, $organizationId, fn () => $user->getAllPermissions()->pluck('name')->sort()->values()->all());
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    private function check(User $user, string $organizationId, string $permission): bool
    {
        try {
            return $this->asOrganization($user, $organizationId, fn () => $user->hasPermissionTo($permission));
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asOrganization(User $user, string $organizationId, callable $callback): mixed
    {
        $previous = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($organizationId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $callback();
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
