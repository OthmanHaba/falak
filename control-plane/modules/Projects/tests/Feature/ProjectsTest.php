<?php

use Illuminate\Support\Facades\Event;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Events\ProjectCreated;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->default = projects_default_env($this->organization)->project;
});

it('creates projects with a production environment', function () {
    Event::fake([ProjectCreated::class]);

    $response = $this->postJson('/projects', ['name' => 'Shop', 'description' => 'Storefront', 'icon' => 'cart'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Shop')
        ->assertJsonPath('data.description', 'Storefront')
        ->assertJsonPath('data.icon', 'cart')
        ->assertJsonPath('data.is_default', false)
        ->assertJsonPath('data.environments.0.slug', 'production')
        ->assertJsonPath('data.environments.0.is_production', true);

    $this->postJson('/projects', ['name' => 'Shop'])->assertUnprocessable()->assertJsonValidationErrors(['name' => 'A project named "Shop" already exists.']);
    $this->postJson('/projects', ['name' => 'X', 'icon' => 'Not An Icon'])->assertUnprocessable()->assertJsonValidationErrors('icon');

    Event::assertDispatched(ProjectCreated::class, fn ($e) => $e->projectId === $response->json('data.id') && ! $e->isDefault);
    expect(AuditEntry::query()->where('action', 'project.created')->where('subject_id', $response->json('data.id'))->exists())->toBeTrue();
});

it('redirects Inertia project creation to the new canvas', function () {
    $response = $this->post('/projects', ['name' => 'Shop'], ['X-Inertia' => 'true']);
    $project = Project::query()->where('name', 'Shop')->sole();

    $response->assertRedirect("/projects/{$project->id}/production");
});

it('updates and deletes projects', function () {
    $project = Project::query()->findOrFail($this->postJson('/projects', ['name' => 'Shop'])->json('data.id'));

    $this->patchJson("/projects/{$project->id}", ['name' => 'Store', 'description' => null])
        ->assertOk()->assertJsonPath('data.name', 'Store');
    $this->patchJson("/projects/{$project->id}", ['name' => 'Default'])->assertUnprocessable()->assertJsonValidationErrors('name');

    $this->deleteJson("/projects/{$project->id}", ['confirm' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('confirm');

    projects_site($this->organization, 'Shop', [], $project->production());
    $this->deleteJson("/projects/{$project->id}", ['confirm' => 'Store'])->assertUnprocessable()->assertJsonValidationErrors(['project' => 'Delete the services of this project first.']);

    $project->services()->delete();
    $this->deleteJson("/projects/{$project->id}", ['confirm' => 'Store'])->assertNoContent();

    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse();
});

it('never deletes the default project', function () {
    $this->deleteJson("/projects/{$this->default->id}", ['confirm' => 'Default'])
        ->assertUnprocessable()->assertJsonValidationErrors(['project' => 'The default project cannot be deleted.']);
});

it('renders the projects grid with service icons, deployments and status', function () {
    $environment = projects_default_env($this->organization);
    projects_database($this->organization, 'db', $environment, 'mysql');
    $site = projects_site($this->organization, 'Web', [], $environment);
    $deployment = projects_deployment($site, 'failed', ['finished_at' => now()]);

    $this->get('/projects')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Projects/Index', false)
        ->has('projects', 1)
        ->where('projects.0.name', 'Default')
        ->where('projects.0.is_default', true)
        ->where('projects.0.services_count', 2)
        ->where('projects.0.services', [
            ['kind' => 'database', 'name' => 'db', 'icon' => 'mysql'],
            ['kind' => 'site', 'name' => 'Web', 'icon' => 'laravel'],
        ])
        ->where('projects.0.environments.0.slug', 'production')
        ->where('projects.0.last_deployment.id', $deployment->id)
        ->where('projects.0.status', 'failed')
        ->where('can.create', true));

    $this->getJson('/projects')->assertOk()->assertJsonPath('data.0.name', 'Default');
});

it('renders the settings page with environments', function () {
    projects_environment($this->organization, 'staging');

    $this->get("/projects/{$this->default->id}/settings")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Projects/Settings', false)
        ->where('project.id', $this->default->id)
        ->has('project.environments', 2)
        ->where('project.environments.0.slug', 'production')
        ->where('project.environments.1.slug', 'staging')
        ->where('can.manage', true));
});

it('summarises the production environment of each project and stars projects per user', function () {
    $environment = projects_default_env($this->organization);
    projects_database($this->organization, 'db', $environment);
    $server = sites_server($this->organization->id, ['name' => 'web-1']);
    $web = projects_site($this->organization, 'Web', [], $environment, [$server]);
    projects_deployment($web, 'succeeded', ['finished_at' => now()]);
    projects_site($this->organization, 'Idle', [], $environment, [$server]);
    $platform = $this->postJson('/projects', ['name' => 'Platform'])->assertCreated()->json('data.id');

    $this->putJson("/projects/{$platform}/favorite")->assertOk()->assertJsonPath('data.favorite', true);
    $this->putJson("/projects/{$platform}/favorite")->assertOk();

    $projects = collect($this->getJson('/projects')->assertOk()->json('data'))->keyBy('name');

    expect($projects['Default']['production'])->toBe(['name' => 'production', 'slug' => 'production', 'services' => 3, 'online' => 2, 'health' => 'partial'])
        ->and($projects['Default']['favorite'])->toBeFalse()
        ->and($projects['Default']['last_activity_at'])->not->toBeNull()
        ->and($projects['Platform']['favorite'])->toBeTrue()
        ->and($projects['Platform']['production'])->toMatchArray(['services' => 0, 'online' => 0, 'health' => 'empty']);

    // Favorites are per user.
    actingAsMember(Role::Viewer, $this->organization);
    expect(collect($this->getJson('/projects')->json('data'))->firstWhere('name', 'Platform')['favorite'])->toBeFalse();

    $this->deleteJson("/projects/{$platform}/favorite")->assertOk()->assertJsonPath('data.favorite', false);
});
