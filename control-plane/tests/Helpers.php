<?php

/*
| Cross-module test helpers. Test files live outside module namespaces, so they may set up
| Identity state directly; production code must still go through Identity\Contracts.
*/

use Illuminate\Support\Str;
use Kiln\Identity\Application\Actions\AssignRole;
use Kiln\Identity\Application\Actions\CreateOrganization;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\User;

/**
 * Create a user who is a member of $organization (or of a fresh organization) with $role,
 * with that organization selected as current.
 *
 * @return array{0: User, 1: Organization}
 */
function memberOf(?Organization $organization = null, Role $role = Role::Owner): array
{
    if ($organization === null) {
        $owner = User::factory()->create();
        $organization = app(CreateOrganization::class)($owner, 'Acme '.Str::random(6));

        if ($role === Role::Owner) {
            return [$owner->refresh(), $organization];
        }
    }

    $user = User::factory()->create();
    $organization->members()->attach($user->id);
    app(AssignRole::class)($user, $organization->id, $role);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return [$user->refresh(), $organization];
}

/**
 * Shortcut: a fresh organization plus an authenticated member with $role.
 *
 * @return array{0: User, 1: Organization}
 */
function actingAsMember(Role $role = Role::Owner, ?Organization $organization = null): array
{
    [$user, $organization] = memberOf($organization, $role);
    test()->actingAs($user);

    return [$user, $organization];
}
