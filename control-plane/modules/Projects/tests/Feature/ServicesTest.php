<?php

use Kiln\Databases\Contracts\DatabaseConnections;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Identity\Contracts\Role;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Sites\Domain\Models\Site;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    sites_fake_source_control();
    $this->staging = projects_environment($this->organization, 'staging');
    $this->base = "/projects/{$this->staging->project_id}/staging";
});

it('creates a site from the canvas through the SiteFactory and places it at the posted position', function () {
    sites_fake_agents();
    $server = sites_server($this->organization->id, ['name' => 'web-1']);

    $response = $this->postJson("{$this->base}/services", sites_input([$server->id], ['kind' => 'site', 'name' => 'Checkout', 'x' => 120, 'y' => -40]))
        ->assertCreated()
        ->assertJsonPath('data.kind', 'site')
        ->assertJsonPath('data.name', 'Checkout')
        ->assertJsonPath('data.position', ['x' => 120, 'y' => -40])
        ->assertJsonPath('data.icon', 'laravel')
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.status_label', 'Not deployed')
        ->assertJsonPath('data.servers.0.name', 'web-1')
        ->assertJsonPath('warnings', []);

    $site = Site::query()->sole();
    $service = Service::query()->sole();

    expect($response->json('data.id'))->toBe($service->id)
        ->and($service->ref_id)->toBe($site->id)
        ->and($service->environment_id)->toBe($this->staging->id);
});

it('validates site input with the Sites rules', function () {
    $this->postJson("{$this->base}/services", ['kind' => 'site', 'name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'framework', 'server_ids']);

    expect(Service::query()->count())->toBe(0);
});

it('creates a database with a user through the Databases contract and places it immediately', function () {
    $agents = FakeAgentGateway::install();
    $engine = databases_engine($this->organization, 'postgresql');

    $response = $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'postgresql', 'server_id' => $engine->server_id, 'name' => 'orders', 'x' => 10, 'y' => 20])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'database')
        ->assertJsonPath('data.name', 'orders')
        ->assertJsonPath('data.icon', 'postgresql')
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.position', ['x' => 10, 'y' => 20]);

    $database = Database::query()->sole();
    expect($response->json('data.ref_id'))->toBe($database->id)
        ->and($agents->last('db.create')['payload']['name'])->toBe('orders')
        ->and(app(DatabaseConnections::class)->variables($database->id))->toHaveKeys(['DB_USERNAME', 'DB_PASSWORD', 'DATABASE_URL'])
        ->and(app(DatabaseConnections::class)->variables($database->id)['DB_USERNAME'])->toBe('orders');

    // db.create converging later does not move or duplicate it.
    $agents->succeed($agents->last('db.create')['handle'], ['changed' => true]);
    expect(Service::query()->sole()->environment_id)->toBe($this->staging->id);
});

it('rejects unsupported or mismatched database engines', function () {
    FakeAgentGateway::install();
    $engine = databases_engine($this->organization, 'mysql');

    $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'postgresql', 'server_id' => $engine->server_id, 'name' => 'orders'])
        ->assertUnprocessable()->assertJsonValidationErrors(['engine']);
    $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'redis', 'server_id' => $engine->server_id, 'name' => 'cache'])
        ->assertUnprocessable()->assertJsonValidationErrors(['engine' => 'Redis services are not supported yet.']);
    $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'mysql', 'server_id' => str_repeat('0', 26), 'name' => 'orders'])
        ->assertUnprocessable()->assertJsonValidationErrors(['server_id']);
    $this->postJson("{$this->base}/services", ['kind' => 'queue'])->assertUnprocessable()->assertJsonValidationErrors(['kind']);

    expect(Database::query()->count())->toBe(0);
});

it('persists card positions per environment', function () {
    $site = projects_site($this->organization, 'Shop', [], $this->staging);
    $service = projects_service('site', $site->id);

    $this->patchJson("{$this->base}/services/{$service->id}/position", ['x' => -250, 'y' => 480])
        ->assertOk()
        ->assertExactJson(['data' => ['id' => $service->id, 'position' => ['x' => -250, 'y' => 480], 'group_id' => null]]);

    expect($service->refresh()->only(['x', 'y']))->toBe(['x' => -250, 'y' => 480]);
    $this->getJson("{$this->base}/canvas")->assertJsonPath('services.0.position', ['x' => -250, 'y' => 480]);

    $this->patchJson("{$this->base}/services/{$service->id}/position", ['x' => 'a'])->assertUnprocessable()->assertJsonValidationErrors(['x', 'y']);
    $this->patchJson("/projects/{$this->staging->project_id}/production/services/{$service->id}/position", ['x' => 1, 'y' => 1])->assertNotFound();
});
