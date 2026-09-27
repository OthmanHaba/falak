<?php

use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Identity\Contracts\Role;
use Kiln\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->environment = projects_default_env($this->organization);
    $this->canvas = "/projects/{$this->environment->project_id}/production";
});

it('renames a canvas service, keeping names unique per environment', function () {
    $shop = projects_site($this->organization, 'Shop', [], $this->environment);
    projects_site($this->organization, 'Api', [], $this->environment);
    $service = projects_service('site', $shop->id);

    $this->patchJson("{$this->canvas}/services/{$service->id}", ['name' => 'Storefront'])->assertOk()
        ->assertJsonPath('data.name', 'Storefront');
    expect($service->refresh()->name)->toBe('Storefront')
        ->and($shop->refresh()->name)->toBe('Shop');

    $this->patchJson("{$this->canvas}/services/{$service->id}", ['name' => 'api'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->patchJson("{$this->canvas}/services/{$service->id}", ['name' => '...'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->patchJson("{$this->canvas}/services/01jnope0000000000000000000", ['name' => 'x'])->assertNotFound();
});

it('forbids viewers from renaming services', function () {
    $shop = projects_site($this->organization, 'Shop', [], $this->environment);
    [$viewer] = memberOf($this->organization, Role::Viewer);

    $this->actingAs($viewer)->patchJson("{$this->canvas}/services/".projects_service('site', $shop->id)->id, ['name' => 'x'])->assertForbidden();
});

it('lists recent deployments and added services in the activity rail', function () {
    $shop = projects_site($this->organization, 'Shop', [], $this->environment);
    $old = projects_deployment($shop, 'failed', ['finished_at' => now()->subHour(), 'created_at' => now()->subHour()]);
    $new = projects_deployment($shop, 'succeeded', ['finished_at' => now()->subMinute(), 'created_at' => now()->subMinutes(2)]);

    $items = $this->getJson("{$this->canvas}/activity")->assertOk()->json('data');

    $deployments = array_values(array_filter($items, fn ($item) => $item['type'] === 'deployment'));

    expect(array_column($deployments, 'deployment_id'))->toBe([$new->id, $old->id])
        ->and($deployments[0]['title'])->toBe('Shop · deployment #2')
        ->and($deployments[0]['detail'])->toBe('Ship it')
        ->and($deployments[0]['status'])->toBe('succeeded')
        ->and(collect($items)->firstWhere('type', 'service')['title'])->toBe('Shop added');
});

it('redirects legacy site, deployment and database pages to the canvas panel', function () {
    $shop = projects_site($this->organization, 'Shop', [], $this->environment);
    $deployment = projects_deployment($shop, 'succeeded');
    [$database] = projects_database($this->organization, 'shop-db', $this->environment);
    $panel = "{$this->canvas}/service/site/{$shop->id}";

    $this->get("/sites/{$shop->id}")->assertRedirect($panel);
    $this->get("/sites/{$shop->id}/deployments")->assertRedirect("{$panel}/deployments");
    $this->get("/sites/{$shop->id}/deployments/{$deployment->id}")->assertRedirect("{$panel}/deployments/{$deployment->id}");
    $this->get("/databases/{$database->id}")->assertRedirect("{$this->canvas}/service/database/{$database->id}");
    $this->get("/databases/databases/{$database->id}")->assertRedirect("{$this->canvas}/service/database/{$database->id}");

    // CLI / API `url` fields point at the Deploy view.
    expect($deployment->url())->toBe(rtrim((string) config('app.url'), '/')."{$panel}/deployments/{$deployment->id}");
    $this->get($panel)->assertOk();
});

it('serves the deployments tab and deploy view as JSON', function () {
    $shop = projects_site($this->organization, 'Shop', [], $this->environment);
    $done = projects_deployment($shop, 'succeeded', ['finished_at' => now()]);
    $running = projects_deployment($shop, 'deploying', ['started_at' => now()]);

    $this->getJson("/sites/{$shop->id}/deployments")->assertOk()
        ->assertJsonPath('data.active.id', $running->id)
        ->assertJsonPath('data.history.data.0.id', $done->id)
        ->assertJsonPath('data.can.create', true)
        ->assertJsonPath('data.can.rollback', true);

    $this->getJson("/sites/{$shop->id}/deployments/{$running->id}")->assertOk()
        ->assertJsonPath('data.deployment.id', $running->id)
        ->assertJsonPath('data.deployment.url', Deployment::query()->find($running->id)->url())
        ->assertJsonStructure(['data' => ['deployment', 'targets', 'steps', 'lines', 'can' => ['cancel', 'redeploy', 'rollback']]]);

    $this->getJson("/sites/{$shop->id}/releases")->assertOk()->assertJsonStructure(['data' => ['releases', 'keep', 'can' => ['rollback']]]);
});

it('serves the database panel as JSON with users, backups and connection info but no secrets', function () {
    [$database, $user] = projects_database($this->organization, 'shop-db', $this->environment);

    $response = $this->getJson("/databases/databases/{$database->id}")->assertOk()
        ->assertJsonPath('data.database.name', 'shop-db')
        ->assertJsonPath('data.users.0.id', $user->id)
        ->assertJsonPath('data.server.engine', 'postgresql')
        ->assertJsonPath('data.can.reveal', true)
        ->assertJsonStructure(['data' => ['connection' => ['driver', 'port', 'hosts'], 'schedules', 'backups', 'restores', 'storage_providers', 'restore_targets', 'options']]);

    expect($response->getContent())->not->toContain('p@ss/word');

    actingAsMember();
    $this->getJson("/databases/databases/{$database->id}")->assertNotFound();
});

it('serves the create picker options as JSON', function () {
    sites_server($this->organization->id, ['name' => 'web-1']);

    $this->getJson('/sites/create')->assertOk()
        ->assertJsonPath('data.options.servers.0.name', 'web-1')
        ->assertJsonStructure(['data' => ['options' => ['frameworks', 'runtimes', 'connections'], 'can_manage_source_control']]);
});

it('lists engine servers as JSON for the create picker', function () {
    projects_database($this->organization, 'shop-db');

    $this->getJson('/databases')->assertOk()
        ->assertJsonPath('data.0.engine', 'postgresql')
        ->assertJsonStructure(['data' => [['id', 'server_id', 'server_name', 'engine', 'engine_label', 'version']]]);
});

it('deletes the site behind a service after typing its name', function () {
    sites_fake_agents();
    $shop = projects_site($this->organization, 'Shop', [], $this->environment);
    $service = projects_service('site', $shop->id);

    $this->deleteJson("{$this->canvas}/services/{$service->id}", ['confirm' => 'shop'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->deleteJson("{$this->canvas}/services/{$service->id}", ['confirm' => 'Shop'])->assertNoContent();

    expect(Site::query()->find($shop->id))->toBeNull()
        ->and(projects_service('site', $shop->id))->toBeNull();
});

it('drops the database behind a service and forbids developers without the permission', function () {
    sites_fake_agents();
    [$database] = projects_database($this->organization, 'shop_db', $this->environment);
    $service = projects_service('database', $database->id);

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->deleteJson("{$this->canvas}/services/{$service->id}", ['confirm' => 'shop_db'])->assertForbidden();

    $this->actingAs($this->user)->deleteJson("{$this->canvas}/services/{$service->id}", ['confirm' => 'shop_db'])->assertNoContent();
    expect($database->refresh()->status->value)->toBe('deleting');
});
