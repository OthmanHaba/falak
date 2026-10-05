<?php

use Falak\Identity\Contracts\Role;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = memberOf();
    $this->environment = projects_default_env($this->organization);
    $this->project = $this->environment->project;
    $site = projects_site($this->organization, 'Shop', [], $this->environment);
    $this->service = projects_service('site', $site->id);
    $this->base = "/projects/{$this->project->id}";
});

it('lets viewers read projects and canvases but not change them', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);

    $this->get('/projects')->assertOk();
    $this->getJson("{$this->base}/production/canvas")->assertOk();
    $this->get("{$this->base}/production")->assertOk()->assertInertia(fn ($page) => $page
        ->where('can', ['view' => true, 'manage' => false, 'create_sites' => false, 'create_databases' => false]));
    $this->getJson('/api/v1/projects')->assertOk();

    $this->postJson('/projects', ['name' => 'X'])->assertForbidden();
    $this->patchJson($this->base, ['name' => 'X'])->assertForbidden();
    $this->postJson("{$this->base}/environments", ['name' => 'staging'])->assertForbidden();
    $this->patchJson("{$this->base}/production/services/{$this->service->id}/position", ['x' => 1, 'y' => 2])->assertForbidden();
    $this->postJson("{$this->base}/production/services", ['kind' => 'database'])->assertForbidden();
    $this->postJson('/api/v1/projects', ['name' => 'X'])->assertForbidden();
});

it('lets developers and admins manage projects', function (Role $role) {
    [$member] = memberOf($this->organization, $role);
    $this->actingAs($member);

    $this->patchJson("{$this->base}/production/services/{$this->service->id}/position", ['x' => 1, 'y' => 2])->assertOk();
    $this->postJson("{$this->base}/environments", ['name' => 'staging'])->assertCreated();
    $this->get("{$this->base}/production")->assertInertia(fn ($page) => $page->where('can.manage', true)->where('can.create_sites', true)->where('can.create_databases', true));
})->with([Role::Developer, Role::Admin, Role::Owner]);

it('hides projects of other organizations', function () {
    actingAsMember(Role::Owner);

    $this->getJson($this->base)->assertNotFound();
    $this->getJson("{$this->base}/production/canvas")->assertNotFound();
    $this->get("{$this->base}/production")->assertNotFound();
    $this->patchJson("{$this->base}/production/services/{$this->service->id}/position", ['x' => 1, 'y' => 2])->assertNotFound();
    $this->getJson("/api/v1/projects/{$this->project->id}")->assertNotFound();
});

it('requires authentication', function () {
    $this->get('/projects')->assertRedirect('/login');
    $this->getJson("{$this->base}/production/canvas")->assertUnauthorized();
    $this->getJson('/api/v1/projects')->assertUnauthorized();
});

it('serves projects and environments over the public API with token abilities', function () {
    [$developer] = memberOf($this->organization, Role::Developer);
    Sanctum::actingAs($developer, ['projects.view']);
    $token = $developer->createToken('cli', ['projects.view'])->accessToken;
    $token->forceFill(['organization_id' => $this->organization->id])->save();
    $developer->withAccessToken($token);

    $this->getJson('/api/v1/projects')->assertOk()
        ->assertJsonPath('data.0.id', $this->project->id)
        ->assertJsonPath('data.0.environments.0.slug', 'production')
        ->assertJsonPath('data.0.environments.0.services_count', 1);
    $this->getJson("/api/v1/projects/{$this->project->id}/environments")->assertOk()->assertJsonPath('data.0.slug', 'production');
    $this->postJson('/api/v1/projects', ['name' => 'Api'])->assertForbidden();
});

it('manages projects and environments over the public API', function () {
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);

    $id = $this->postJson('/api/v1/projects', ['name' => 'Api'])->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/projects/{$id}", ['description' => 'Public API'])->assertOk()->assertJsonPath('data.description', 'Public API');
    $this->postJson("/api/v1/projects/{$id}/environments", ['name' => 'Staging'])->assertCreated()->assertJsonPath('data.slug', 'staging');
    $this->patchJson("/api/v1/projects/{$id}/environments/staging", ['name' => 'QA'])->assertOk()->assertJsonPath('data.slug', 'qa');
    $this->deleteJson("/api/v1/projects/{$id}/environments/qa")->assertNoContent();
    $this->deleteJson("/api/v1/projects/{$id}")->assertNoContent();
    $this->deleteJson("/api/v1/projects/{$this->project->id}")->assertUnprocessable();
});
