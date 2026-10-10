<?php

use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\SharedProps;
use Falak\Projects\Domain\Models\Project;

require_once __DIR__.'/../Support/helpers.php';

it('shares the falak prop on every authenticated page', function () {
    [, $organization] = actingAsMember(Role::Viewer);
    $environment = projects_default_env($organization);
    $shop = Project::query()->create(['organization_id' => $organization->id, 'name' => 'Shop', 'icon' => 'cart']);
    $shop->environments()->create(['organization_id' => $organization->id, 'name' => 'production', 'slug' => 'production', 'is_production' => true]);
    $staging = $shop->environments()->create(['organization_id' => $organization->id, 'name' => 'staging', 'slug' => 'staging']);

    expect(app(SharedProps::class)->has('falak'))->toBeTrue();

    $this->get('/settings/profile')->assertOk()->assertInertia(fn ($page) => $page
        ->where('falak.projects.0', [
            'id' => $environment->project_id,
            'name' => 'Default',
            'icon' => null,
            'environments' => [['id' => $environment->id, 'name' => 'production', 'slug' => 'production', 'is_production' => true, 'is_preview' => false]],
        ])
        ->where('falak.projects.1.name', 'Shop')
        ->where('falak.projects.1.icon', 'cart')
        ->where('falak.projects.1.environments.1', ['id' => $staging->id, 'name' => 'staging', 'slug' => 'staging', 'is_production' => false, 'is_preview' => false])
        ->where('falak.current', ['project_id' => null, 'environment_id' => null]));

    // The URL decides the current project / environment, and it is remembered for other pages.
    $this->get("/projects/{$shop->id}/staging")->assertInertia(fn ($page) => $page
        ->where('falak.current', ['project_id' => $shop->id, 'environment_id' => $staging->id]));
    $this->get("/projects/{$shop->id}/settings")->assertInertia(fn ($page) => $page
        ->where('falak.current', ['project_id' => $shop->id, 'environment_id' => $shop->production()->id]));
    $this->get('/settings/profile')->assertInertia(fn ($page) => $page
        ->where('falak.current', ['project_id' => $shop->id, 'environment_id' => $staging->id]));
});

it('only lists projects of the current organization', function () {
    [, $other] = memberOf();
    [, $organization] = actingAsMember();

    $this->get('/projects')->assertInertia(fn ($page) => $page
        ->has('falak.projects', 1)
        ->where('falak.projects.0.id', projects_default_env($organization)->project_id)
        ->where('falak.projects.0.id', fn ($id) => $id !== projects_default_env($other)->project_id));
});

it('does not share falak with guests', function () {
    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->missing('falak'));
});
