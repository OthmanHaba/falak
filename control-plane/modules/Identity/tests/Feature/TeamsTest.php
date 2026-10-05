<?php

use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Identity\Domain\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

it('creates, updates, syncs members of and deletes teams', function () {
    [$owner, $organization] = actingAsMember(Role::Admin);
    [$developer] = memberOf($organization, Role::Developer);

    $this->post('/organization/teams', ['name' => 'Backend', 'description' => 'API folks'])->assertSessionHasNoErrors();
    $team = Team::query()->sole();

    $this->post('/organization/teams', ['name' => 'Backend'])->assertSessionHasErrors('name');

    $this->patch("/organization/teams/{$team->id}", ['name' => 'Platform', 'description' => null])->assertSessionHasNoErrors();
    expect($team->fresh()->name)->toBe('Platform');

    $this->put("/organization/teams/{$team->id}/members", ['user_ids' => [$owner->id, $developer->id]])->assertSessionHasNoErrors();
    expect($team->members()->pluck('identity_users.id')->sort()->values()->all())->toBe(collect([$owner->id, $developer->id])->sort()->values()->all());

    $this->get('/settings/teams')->assertInertia(fn (Assert $page) => $page
        ->component('Identity/organizations/teams', false)
        ->has('teams', 1)
        ->has('teams.0.members', 2)
        ->has('members', 3)
        ->where('canManage', true));

    $this->put("/organization/teams/{$team->id}/members", ['user_ids' => []])->assertSessionHasNoErrors();
    expect($team->members()->count())->toBe(0);

    $this->delete("/organization/teams/{$team->id}")->assertRedirect();
    expect(Team::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'like', 'team.%')->pluck('action')->all())
        ->toContain('team.created', 'team.updated', 'team.members_synced', 'team.deleted');
});

it('rejects non-members in teams', function () {
    [, $organization] = actingAsMember();
    [$stranger] = memberOf();
    $this->post('/organization/teams', ['name' => 'Ops']);
    $team = Team::query()->sole();

    $this->put("/organization/teams/{$team->id}/members", ['user_ids' => [$stranger->id]])->assertSessionHasErrors('user_ids');
    expect($team->members()->count())->toBe(0);
});

it('lets viewers see teams but not manage them', function () {
    [, $organization] = memberOf();
    [$viewer] = memberOf($organization, Role::Viewer);
    $team = $organization->teams()->create(['name' => 'Ops']);
    $this->actingAs($viewer);

    $this->get('/settings/teams')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->post('/organization/teams', ['name' => 'New'])->assertForbidden();
    $this->patch("/organization/teams/{$team->id}", ['name' => 'X'])->assertForbidden();
    $this->put("/organization/teams/{$team->id}/members", ['user_ids' => []])->assertForbidden();
    $this->delete("/organization/teams/{$team->id}")->assertForbidden();
});

it('scopes teams to the current organization', function () {
    [, $other] = memberOf();
    $foreign = $other->teams()->create(['name' => 'Foreign']);
    actingAsMember();

    $this->patch("/organization/teams/{$foreign->id}", ['name' => 'Hijack'])->assertNotFound();
    $this->delete("/organization/teams/{$foreign->id}")->assertNotFound();
});
