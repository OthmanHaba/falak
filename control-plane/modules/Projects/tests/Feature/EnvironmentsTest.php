<?php

use Falak\Identity\Contracts\Role;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Service;
use Falak\Projects\Events\EnvironmentCreated;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    sites_fake_source_control();
    $this->production = projects_default_env($this->organization);
    $this->project = $this->production->project;
});

it('creates an empty environment with a unique slug', function () {
    Event::fake([EnvironmentCreated::class]);

    $this->postJson("/projects/{$this->project->id}/environments", ['name' => 'Staging'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Staging')
        ->assertJsonPath('data.slug', 'staging')
        ->assertJsonPath('data.is_production', false)
        ->assertJsonPath('data.forked_from_id', null)
        ->assertJsonPath('data.services_count', 0)
        ->assertJsonPath('warnings', []);

    $this->postJson("/projects/{$this->project->id}/environments", ['name' => 'staging'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson("/projects/{$this->project->id}/environments", ['name' => 'Settings'])->assertCreated()->assertJsonPath('data.slug', 'settings-env');
    $this->postJson("/projects/{$this->project->id}/environments", ['name' => '../x'])->assertUnprocessable();

    Event::assertDispatched(EnvironmentCreated::class, fn ($e) => $e->slug === 'staging' && ! $e->isProduction);
});

it('duplicates site configs and variables into a new environment, without servers or databases', function () {
    $server = sites_server($this->organization->id);
    projects_database($this->organization, 'db', $this->production);
    $shop = projects_site($this->organization, 'Shop', ['APP_KEY' => 'base64:abc', 'DATABASE_URL' => '${{ db.DATABASE_URL }}'], $this->production, [$server], [
        'deploy_script' => "echo custom\n\$FALAK_FETCH\n\$FALAK_ACTIVATE\n",
        'branch' => 'main',
        'push_to_deploy' => true,
    ]);
    EnvironmentVersion::query()->where('site_id', $shop->id)->update(['exposed' => json_encode(['APP_KEY'])]);
    Service::query()->where('ref_id', $shop->id)->update(['x' => 640, 'y' => 320]);

    $response = $this->postJson("/projects/{$this->project->id}/environments", ['name' => 'Staging', 'from_environment_id' => $this->production->id])
        ->assertCreated()
        ->assertJsonPath('data.forked_from_id', $this->production->id)
        ->assertJsonPath('data.services_count', 1)
        ->assertJsonPath('warnings', ['Databases are not duplicated (db); create them in this environment so references resolve.']);

    $staging = Environment::query()->findOrFail($response->json('data.id'));
    $copyService = Service::query()->where('environment_id', $staging->id)->sole();
    $copy = Site::query()->with(['targets', 'latestEnvironment'])->findOrFail($copyService->ref_id);

    expect($copyService->name)->toBe('Shop')
        ->and([$copyService->x, $copyService->y])->toBe([640, 320])
        ->and($copy->id)->not->toBe($shop->id)
        ->and($copy->name)->toBe('Shop-staging')
        ->and($copy->targets)->toHaveCount(0)
        ->and($copy->push_to_deploy)->toBeFalse()
        ->and($copy->branch)->toBe('main')
        ->and($copy->deploy_script)->toBe("echo custom\n\$FALAK_FETCH\n\$FALAK_ACTIVATE\n")
        ->and($copy->latestEnvironment->version)->toBe(1)
        ->and($copy->latestEnvironment->variables)->toBe(['APP_KEY' => 'base64:abc', 'DATABASE_URL' => '${{ db.DATABASE_URL }}'])
        ->and($copy->latestEnvironment->exposed)->toBe(['APP_KEY'])
        ->and(Service::query()->where('environment_id', $staging->id)->where('kind', 'database')->exists())->toBeFalse();

    // The source is untouched.
    expect(Service::query()->where('environment_id', $this->production->id)->count())->toBe(2);
});

it('rejects duplicating from another project', function () {
    $other = $this->postJson('/projects', ['name' => 'Other'])->assertCreated()->json('data.environments.0.id');

    $this->postJson("/projects/{$this->project->id}/environments", ['name' => 'Staging', 'from_environment_id' => $other])->assertNotFound();
});

it('renames environments and deletes empty non-production ones', function () {
    $staging = projects_environment($this->organization, 'staging');

    $this->patchJson("/projects/{$this->project->id}/environments/staging", ['name' => 'QA'])
        ->assertOk()->assertJsonPath('data.slug', 'qa')->assertJsonPath('data.name', 'QA');

    projects_site($this->organization, 'Shop', [], $staging->refresh());

    $this->deleteJson("/projects/{$this->project->id}/environments/qa")->assertUnprocessable()->assertJsonValidationErrors(['environment' => 'Delete the services of this environment first.']);
    $this->deleteJson("/projects/{$this->project->id}/environments/production")->assertUnprocessable()->assertJsonValidationErrors(['environment' => 'The production environment cannot be deleted.']);

    Service::query()->where('environment_id', $staging->id)->delete();
    $this->deleteJson("/projects/{$this->project->id}/environments/{$staging->id}")->assertNoContent();

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeFalse();
});

it('redirects Inertia visits back to the new environment canvas', function () {
    $this->post("/projects/{$this->project->id}/environments", ['name' => 'Preview'], ['X-Inertia' => 'true'])
        ->assertRedirect("/projects/{$this->project->id}/preview");
});
