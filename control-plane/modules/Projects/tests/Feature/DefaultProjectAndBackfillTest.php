<?php

use Illuminate\Support\Facades\Event;
use Kiln\Identity\Application\Actions\DeleteOrganization;
use Kiln\Identity\Contracts\Role;
use Kiln\Projects\Application\Actions\BackfillProjects;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Projects\Domain\Models\Environment;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Projects\Events\EnvironmentCreated;
use Kiln\Projects\Events\ProjectCreated;

require_once __DIR__.'/../Support/helpers.php';

it('gives every new organization a Default project with a production environment', function () {
    Event::fake([ProjectCreated::class, EnvironmentCreated::class]);
    [, $organization] = memberOf();

    $project = Project::query()->where('organization_id', $organization->id)->sole();

    expect($project->name)->toBe('Default')
        ->and($project->is_default)->toBeTrue()
        ->and($project->environments)->toHaveCount(1)
        ->and($project->environments->first()->only(['name', 'slug', 'is_production']))->toBe(['name' => 'production', 'slug' => 'production', 'is_production' => true])
        ->and(app(ProjectDirectory::class)->defaultEnvironment($organization->id)?->id)->toBe($project->environments->first()->id);

    Event::assertDispatched(ProjectCreated::class, fn ($e) => $e->projectId === $project->id && $e->isDefault);
    Event::assertDispatched(EnvironmentCreated::class, fn ($e) => $e->projectId === $project->id && $e->isProduction);
});

it('backfills default projects and places existing sites and databases, idempotently', function () {
    [, $organization] = memberOf();
    [, $other] = memberOf();

    // State before the Projects module existed: no projects, unplaced services.
    Service::query()->delete();
    Environment::query()->delete();
    Project::query()->delete();

    $shop = projects_site($organization, 'Shop');
    $api = projects_site($organization, 'Api');
    [$database] = projects_database($organization, 'shop');
    $foreign = projects_site($other, 'Blog');

    $counts = app(BackfillProjects::class)();

    expect($counts)->toBe(['organizations' => 2, 'projects' => 2, 'sites' => 3, 'databases' => 1]);

    $environment = projects_default_env($organization);
    expect(projects_service('site', $shop->id)?->environment_id)->toBe($environment->id)
        ->and(projects_service('site', $api->id)?->environment_id)->toBe($environment->id)
        ->and(projects_service('database', $database->id)?->environment_id)->toBe($environment->id)
        ->and(projects_service('site', $foreign->id)?->environment_id)->toBe(projects_default_env($other)->id)
        ->and(projects_service('site', $shop->id)?->name)->toBe('Shop');

    // Distinct auto-layout cells.
    $positions = Service::query()->where('environment_id', $environment->id)->get()->map(fn ($s) => "{$s->x}:{$s->y}")->all();
    expect(array_unique($positions))->toHaveCount(3);

    // Second run changes nothing.
    expect(app(BackfillProjects::class)())->toBe(['organizations' => 2, 'projects' => 0, 'sites' => 0, 'databases' => 0])
        ->and(Project::query()->count())->toBe(2)
        ->and(Environment::query()->count())->toBe(2)
        ->and(Service::query()->count())->toBe(4);
});

it('runs the backfill as an artisan command, optionally for one organization', function () {
    [, $organization] = memberOf();
    [, $other] = memberOf();
    Service::query()->delete();
    Environment::query()->delete();
    Project::query()->delete();
    projects_site($organization, 'Shop');
    projects_site($other, 'Blog');

    $this->artisan('projects:backfill', ['--organization' => strtoupper($organization->id)])
        ->expectsOutputToContain('Checked 1 organization(s): 1 default project(s) created, 1 site(s) and 0 database(s) placed.')
        ->assertSuccessful();

    expect(Project::query()->where('organization_id', $other->id)->exists())->toBeFalse();

    $this->artisan('projects:backfill')->expectsOutputToContain('Checked 2 organization(s): 1 default project(s) created, 1 site(s)')->assertSuccessful();
    $this->artisan('projects:backfill')->expectsOutputToContain('0 default project(s) created, 0 site(s) and 0 database(s) placed.')->assertSuccessful();
});

it('keeps a user project named Default apart from the default project', function () {
    [, $organization] = memberOf();
    Project::query()->where('organization_id', $organization->id)->update(['is_default' => false]);

    app(BackfillProjects::class)();

    expect(Project::query()->where('organization_id', $organization->id)->where('is_default', true)->value('name'))->toBe('Default 2');
});

it('deletes the projects of a deleted organization', function () {
    [$owner, $organization] = actingAsMember(Role::Owner);
    projects_site($organization, 'Shop', environment: projects_default_env($organization));

    app(DeleteOrganization::class)($organization, $owner);

    expect(Project::query()->where('organization_id', $organization->id)->exists())->toBeFalse()
        ->and(Service::query()->where('organization_id', $organization->id)->exists())->toBeFalse();
});
