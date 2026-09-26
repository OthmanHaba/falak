<?php

use Illuminate\Auth\Access\AuthorizationException;
use Kiln\Identity\Application\Actions\AssignRole;
use Kiln\Identity\Application\Actions\SyncPermissions;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Contracts\OrganizationDirectory;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('syncs the four global roles after migrations', function () {
    expect(Role::query()->whereNull('organization_id')->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'developer', 'owner', 'viewer']);

    $owner = Role::query()->where('name', 'owner')->sole();
    expect($owner->permissions()->count())->toBe(count(app(PermissionRegistry::class)->all()));
});

it('grants registered permissions to their roles and always to the owner', function () {
    app(PermissionRegistry::class)->register('widgets.use', [RoleEnum::Developer], 'Use widgets');
    app(SyncPermissions::class)();

    $holders = fn (string $permission) => Role::query()
        ->whereHas('permissions', fn ($q) => $q->where('name', $permission))
        ->pluck('name')->sort()->values()->all();

    expect($holders('widgets.use'))->toBe(['developer', 'owner'])
        ->and($holders('members.manage'))->toBe(['admin', 'owner'])
        ->and($holders('organization.delete'))->toBe(['owner'])
        ->and($holders('members.view'))->toBe(['admin', 'developer', 'owner', 'viewer']);

    [$developer, $organization] = memberOf(role: RoleEnum::Developer);
    expect(app(OrganizationAccess::class)->can($developer, $organization->id, 'widgets.use'))->toBeTrue();
});

it('removes stale permissions and is idempotent', function () {
    Permission::findOrCreate('stale.permission', 'web');

    $result = app(SyncPermissions::class)();
    app(SyncPermissions::class)();

    expect(Permission::query()->where('name', 'stale.permission')->exists())->toBeFalse()
        ->and($result['roles'])->toBe(4)
        ->and(Permission::query()->count())->toBe($result['permissions']);
});

it('exposes a sync command', function () {
    $this->artisan('identity:permissions:sync')->assertSuccessful();
});

it('validates permission names', function () {
    app(PermissionRegistry::class)->register('NotValid', [RoleEnum::Admin]);
})->throws(InvalidArgumentException::class);

it('scopes roles and permissions per organization', function () {
    [$user, $first] = memberOf(role: RoleEnum::Admin);
    [, $second] = memberOf();
    $second->members()->attach($user->id);
    app(AssignRole::class)($user, $second->id, RoleEnum::Viewer);

    $access = app(OrganizationAccess::class);
    expect($access->roleOf($user->id, $first->id))->toBe(RoleEnum::Admin)
        ->and($access->roleOf($user->id, $second->id))->toBe(RoleEnum::Viewer)
        ->and($access->can($user, $first->id, 'members.manage'))->toBeTrue()
        ->and($access->can($user, $second->id, 'members.manage'))->toBeFalse()
        ->and($access->can($user, $second->id, 'unknown.permission'))->toBeFalse()
        ->and($access->can(null, $first->id, 'members.view'))->toBeFalse()
        ->and($access->isMember($user->id, $second->id))->toBeTrue()
        ->and($access->memberIds($second->id))->toContain($user->id);

    expect(fn () => $access->authorize($user, $second->id, 'members.manage'))->toThrow(AuthorizationException::class);
});

it('provides an organization directory', function () {
    [$user, $organization] = memberOf();
    $directory = app(OrganizationDirectory::class);

    expect($directory->find($organization->id)?->name)->toBe($organization->name)
        ->and($directory->find('missing'))->toBeNull()
        ->and($directory->findUser($user->id)?->email)->toBe($user->email)
        ->and($directory->members($organization->id))->toHaveCount(1)
        ->and($directory->members('missing'))->toBe([]);
});
