<?php

use Illuminate\Support\Facades\Event;
use Falak\Databases\Domain\Models\Database;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Events\ServiceLinked;
use Falak\Projects\Events\ServiceUnlinked;
use Falak\Servers\Contracts\ServerType;
use Falak\Sites\Domain\Models\Site;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    $this->git = sites_fake_source_control();
    $this->server = sites_server($this->organization->id, ['name' => 'web-1']);
});

it('places sites created without a placement in the default project production environment', function () {
    Event::fake([ServiceLinked::class]);

    $this->post('/sites', sites_input([$this->server->id]))->assertSessionHasNoErrors();

    $site = Site::query()->sole();
    $service = projects_service('site', $site->id);

    expect($service->environment_id)->toBe(projects_default_env($this->organization)->id)
        ->and($service->name)->toBe('Shop')
        ->and(app(ProjectDirectory::class)->projectOf('site', $site->id)?->projectId)->toBe($service->project_id);

    Event::assertDispatched(ServiceLinked::class, fn ($e) => $e->refId === $site->id && $e->kind === 'site');
});

it('places sites in the environment given to the web form and the API', function () {
    $staging = projects_environment($this->organization, 'staging');

    $this->post('/sites', sites_input([$this->server->id], ['environment_id' => $staging->id]))->assertSessionHasNoErrors();
    $this->postJson('/api/v1/sites', sites_input([$this->server->id], ['name' => 'Api', 'project_id' => $staging->project_id, 'environment_id' => strtoupper($staging->id)]))->assertCreated();

    expect(projects_service('site', Site::query()->where('name', 'Shop')->value('id'))->environment_id)->toBe($staging->id)
        ->and(projects_service('site', Site::query()->where('name', 'Api')->value('id'))->environment_id)->toBe($staging->id);
});

it('places sites in the production environment of a given project', function () {
    $this->actingAs($this->user);
    $project = Project::query()->create(['organization_id' => $this->organization->id, 'name' => 'Shop']);
    $production = $project->environments()->create(['organization_id' => $this->organization->id, 'name' => 'production', 'slug' => 'production', 'is_production' => true]);

    $this->postJson('/api/v1/sites', sites_input([$this->server->id], ['project_id' => $project->id]))->assertCreated();

    expect(projects_service('site', Site::query()->value('id'))->environment_id)->toBe($production->id);
});

it('rejects placements outside the organization or across projects', function () {
    [, $other] = memberOf();
    $foreign = projects_default_env($other);
    $staging = projects_environment($this->organization, 'staging');
    $project = Project::query()->create(['organization_id' => $this->organization->id, 'name' => 'Other']);

    $this->postJson('/api/v1/sites', sites_input([$this->server->id], ['environment_id' => $foreign->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['environment_id' => 'Unknown environment.']);
    $this->postJson('/api/v1/sites', sites_input([$this->server->id], ['project_id' => $foreign->project_id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['project_id' => 'Unknown project.']);
    $this->postJson('/api/v1/sites', sites_input([$this->server->id], ['project_id' => $project->id, 'environment_id' => $staging->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['environment_id' => 'The environment does not belong to the project.']);

    expect(Site::query()->count())->toBe(0);
});

it('unlinks deleted sites', function () {
    Event::fake([ServiceUnlinked::class]);
    $this->post('/sites', sites_input([$this->server->id]))->assertSessionHasNoErrors();
    $site = Site::query()->sole();
    [$admin] = memberOf($this->organization, Role::Admin);

    $this->actingAs($admin)->delete("/sites/{$site->id}", ['name' => $site->name])->assertSessionHasNoErrors();

    expect(Site::query()->count())->toBe(0)
        ->and(projects_service('site', $site->id))->toBeNull();
    Event::assertDispatched(ServiceUnlinked::class, fn ($e) => $e->refId === $site->id);
});

it('places databases once they exist and unlinks them when dropped', function () {
    $agents = FakeAgentGateway::install();
    $engine = databases_engine($this->organization, 'postgresql', ServerType::Database);

    $this->post("/databases/servers/{$engine->id}/databases", ['name' => 'shop'])->assertSessionHasNoErrors();
    $database = Database::query()->sole();

    expect(projects_service('database', $database->id))->toBeNull();

    $agents->succeed($agents->last('db.create')['handle'], ['changed' => true]);

    $service = projects_service('database', $database->id);
    expect($service?->environment_id)->toBe(projects_default_env($this->organization)->id)
        ->and($service->name)->toBe('shop');

    $this->delete("/databases/databases/{$database->id}", ['confirm' => 'shop'])->assertSessionHasNoErrors();
    $agents->succeed($agents->last('db.drop')['handle'], ['changed' => true]);

    expect(projects_service('database', $database->id))->toBeNull();
});

it('keeps service names unique per environment and builds panel urls', function () {
    $environment = projects_default_env($this->organization);
    [$a] = projects_database($this->organization, 'app', $environment);
    [$b] = projects_database($this->organization, 'app', $environment, 'mysql');

    expect(projects_service('database', $a->id)->name)->toBe('app')
        ->and(projects_service('database', $b->id)->name)->toBe('app-2')
        ->and(app(ProjectDirectory::class)->serviceUrl('database', $b->id, 'backups'))
        ->toBe("/projects/{$environment->project_id}/production/service/database/{$b->id}/backups")
        ->and(app(ProjectDirectory::class)->serviceUrl('site', $b->id))->toBeNull();
});
