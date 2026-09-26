<?php

use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Kiln\Identity\Application\Actions\CreateApiToken;
use Kiln\Identity\Application\Actions\CreateOrganization;
use Kiln\Identity\Application\Actions\CreateTeam;
use Kiln\Identity\Application\Actions\SyncTeamMembers;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Identity\Domain\Models\PersonalAccessToken;
use Kiln\Identity\Events\MemberRemoved;
use Kiln\Identity\Events\MemberRoleChanged;

it('lists members with roles for every member', function () {
    [$owner, $organization] = actingAsMember();
    memberOf($organization, Role::Developer);
    [$viewer] = memberOf($organization, Role::Viewer);

    $this->get('/organization/members')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Identity/organizations/members', false)
        ->has('members', 3)
        ->where('myRole', 'owner')
        ->where('canManage', true)
        ->has('roles', 4)
        ->where('members', fn ($members) => collect($members)->firstWhere('id', $owner->id)['role'] === 'owner'));

    $this->actingAs($viewer)->get('/organization/members')->assertInertia(fn (Assert $page) => $page
        ->where('canManage', false)
        ->where('invitations', []));
});

it('lets owners and admins change roles of members they outrank', function () {
    Event::fake([MemberRoleChanged::class]);
    [, $organization] = actingAsMember(Role::Admin);
    [$developer] = memberOf($organization, Role::Developer);

    $this->patch("/organization/members/{$developer->id}", ['role' => 'viewer'])->assertSessionHasNoErrors();

    expect(app(OrganizationAccess::class)->roleOf($developer->id, $organization->id))->toBe(Role::Viewer);
    Event::assertDispatched(MemberRoleChanged::class, fn ($e) => $e->userId === $developer->id && $e->from === 'developer' && $e->to === 'viewer');
    expect(AuditEntry::query()->where('action', 'member.role_changed')->sole()->context)->toBe(['from' => 'developer', 'to' => 'viewer']);

    // Admins may grant up to their own role.
    $this->patch("/organization/members/{$developer->id}", ['role' => 'admin'])->assertSessionHasNoErrors();
    expect(app(OrganizationAccess::class)->roleOf($developer->id, $organization->id))->toBe(Role::Admin);
});

it('enforces role change rules', function () {
    [$admin, $organization] = actingAsMember(Role::Admin);
    $owner = $organization->owner;
    [$otherAdmin] = memberOf($organization, Role::Admin);
    [$developer] = memberOf($organization, Role::Developer);

    $this->patch("/organization/members/{$owner->id}", ['role' => 'viewer'])->assertSessionHasErrors('role');
    $this->patch("/organization/members/{$otherAdmin->id}", ['role' => 'viewer'])->assertSessionHasErrors('role');
    $this->patch("/organization/members/{$admin->id}", ['role' => 'viewer'])->assertSessionHasErrors('role');
    $this->patch("/organization/members/{$developer->id}", ['role' => 'owner'])->assertSessionHasErrors('role');
    $this->patch("/organization/members/{$developer->id}", ['role' => 'superuser'])->assertSessionHasErrors('role');

    $this->actingAs($developer)->patch("/organization/members/{$otherAdmin->id}", ['role' => 'viewer'])->assertForbidden();

    $access = app(OrganizationAccess::class);
    expect($access->roleOf($owner->id, $organization->id))->toBe(Role::Owner)
        ->and($access->roleOf($otherAdmin->id, $organization->id))->toBe(Role::Admin)
        ->and($access->roleOf($developer->id, $organization->id))->toBe(Role::Developer);
});

it('returns 404 for users outside the organization', function () {
    actingAsMember();
    [$stranger] = memberOf();

    $this->patch("/organization/members/{$stranger->id}", ['role' => 'viewer'])->assertNotFound();
    $this->delete("/organization/members/{$stranger->id}")->assertNotFound();
});

it('removes members and revokes their organization tokens and team memberships', function () {
    Event::fake([MemberRemoved::class]);
    [, $organization] = actingAsMember();
    [$developer] = memberOf($organization, Role::Developer);
    $team = app(CreateTeam::class)($organization, 'Backend');
    app(SyncTeamMembers::class)($team, [$developer->id]);
    app(CreateApiToken::class)($developer, $organization->id, 'here', ['*']);
    $elsewhere = app(CreateOrganization::class)($developer, 'Own');
    app(CreateApiToken::class)($developer, $elsewhere->id, 'there', ['*']);

    $this->delete("/organization/members/{$developer->id}")->assertRedirect();

    expect(app(OrganizationAccess::class)->isMember($developer->id, $organization->id))->toBeFalse()
        ->and(app(OrganizationAccess::class)->roleOf($developer->id, $organization->id))->toBeNull()
        ->and($team->members()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->pluck('name')->all())->toBe(['there'])
        ->and(AuditEntry::query()->where('action', 'member.removed')->exists())->toBeTrue();
    Event::assertDispatched(MemberRemoved::class);
});

it('enforces removal rules', function () {
    [$admin, $organization] = actingAsMember(Role::Admin);
    [$otherAdmin] = memberOf($organization, Role::Admin);

    $this->delete("/organization/members/{$organization->owner_id}")->assertSessionHasErrors('member');
    $this->delete("/organization/members/{$otherAdmin->id}")->assertSessionHasErrors('member');

    [$viewer] = memberOf($organization, Role::Viewer);
    $this->actingAs($viewer)->delete("/organization/members/{$admin->id}")->assertForbidden();

    expect(app(OrganizationAccess::class)->memberIds($organization->id))->toHaveCount(4);
});

it('lets a member leave and moves their current organization elsewhere', function () {
    [, $organization] = memberOf();
    [$developer] = memberOf($organization, Role::Developer);
    $own = app(CreateOrganization::class)($developer, 'Own');
    $developer->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($developer)->delete("/organization/members/{$developer->id}")->assertRedirect(route('dashboard'));

    expect($developer->fresh()->current_organization_id)->toBe($own->id)
        ->and(AuditEntry::query()->where('action', 'member.left')->exists())->toBeTrue();
});

it('does not let the owner leave', function () {
    [$owner] = actingAsMember();

    $this->delete("/organization/members/{$owner->id}")->assertSessionHasErrors('member');
});
