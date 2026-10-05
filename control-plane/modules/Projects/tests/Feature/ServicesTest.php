<?php

use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Domain\Models\Database;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Projects\Domain\Models\Service;
use Falak\Servers\Contracts\ServerType;
use Falak\Sites\Domain\Models\Site;
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
        ->assertUnprocessable()->assertJsonValidationErrors(['server_id' => 'The server does not run Redis.']);
    $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'memcached', 'server_id' => $engine->server_id, 'name' => 'cache'])
        ->assertUnprocessable()->assertJsonValidationErrors(['engine']);
    $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'mysql', 'server_id' => str_repeat('0', 26), 'name' => 'orders'])
        ->assertUnprocessable()->assertJsonValidationErrors(['server_id']);
    $this->postJson("{$this->base}/services", ['kind' => 'queue'])->assertUnprocessable()->assertJsonValidationErrors(['kind']);

    expect(Database::query()->count())->toBe(0);
});

it('creates a Redis instance from the canvas: card, REDIS_* keys and references for a site on the server', function () {
    $agents = FakeAgentGateway::install();
    $server = databases_server($this->organization, 'postgresql', ServerType::App, ['stack' => ['database' => 'postgresql', 'cache' => 'redis']]);
    Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $this->organization->id, 'facts' => ['features' => ['db.redis'], 'runtimes' => ['redis' => ['7.0.15']], 'memory_bytes' => 4 * 1024 ** 3]]);

    $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'redis', 'server_id' => $server->id, 'name' => 'cache', 'maxmemory_mb' => 256, 'eviction' => 'allkeys-lru'])
        ->assertCreated()
        ->assertJsonPath('data.icon', 'redis')
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.subtitle', "Redis 7.0 · 256 MB · {$server->name}")
        ->assertJsonPath('data.volumes.0.name', 'redis-data');

    $apply = $agents->last('db.redis.apply');
    expect($apply['payload'])->toMatchArray(['name' => 'cache', 'port' => 6380, 'maxmemory_mb' => 256, 'eviction' => 'allkeys-lru']);
    $agents->succeed($apply['handle'], ['changed' => true, 'restarted' => true, 'port' => 6380]);

    $this->getJson("{$this->base}/variables")->assertOk()
        ->assertJsonPath('data.services.0.keys', DatabaseConnections::REDIS_KEYS);

    $web = projects_site($this->organization, 'Web', [], $this->staging, [$server]);
    $result = app(VariableReferences::class)->resolve($this->staging->id, $web->id, ['REDIS_URL' => '${{ cache.REDIS_URL }}', 'REDIS_PORT' => '${{ cache.REDIS_PORT }}']);
    expect($result->errors)->toBe([])
        ->and($result->variables)->toBe(['REDIS_URL' => "redis://default:{$apply['payload']['password']}@127.0.0.1:6380", 'REDIS_PORT' => '6380']);

    // A site on another server gets the reason instead of a host it cannot reach.
    $other = projects_site($this->organization, 'Elsewhere', [], $this->staging, [sites_server($this->organization->id, ['name' => 'web-9'])]);
    $result = app(VariableReferences::class)->resolve($this->staging->id, $other->id, ['REDIS_HOST' => '${{ cache.REDIS_HOST }}']);
    expect($result->errors[0] ?? '')->toContain('cache.REDIS_HOST cannot be used here');
});

it('creates a Redis instance through the API with a token', function () {
    $agents = FakeAgentGateway::install();
    $server = databases_server($this->organization, 'postgresql', ServerType::App, ['stack' => ['cache' => 'valkey']]);
    Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $this->organization->id, 'facts' => ['features' => ['db.redis']]]);
    $token = $this->user->createToken('cli', ['*']);
    $token->accessToken->forceFill(['organization_id' => $this->organization->id])->save();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)->postJson("/api/v1/projects/{$this->staging->project_id}/environments/staging/services", ['kind' => 'database', 'engine' => 'valkey', 'server_id' => $server->id, 'name' => 'sessions', 'persistence' => 'aof'])
        ->assertCreated()->assertJsonPath('data.icon', 'valkey')->assertJsonPath('data.name', 'sessions');
    expect($agents->last('db.redis.apply')['payload'])->toMatchArray(['engine' => 'valkey', 'persistence' => 'aof']);

    // Upper-case ids (as the CLI prints them) and environment ids work; another organization's project is not found.
    $this->withToken($token->plainTextToken)->postJson('/api/v1/projects/'.strtoupper($this->staging->project_id)."/environments/{$this->staging->id}/services", ['kind' => 'database', 'engine' => 'valkey', 'server_id' => $server->id, 'name' => 'queue'])
        ->assertCreated();
    [, $stranger] = memberOf();
    $other = projects_default_env($stranger);
    $this->withToken($token->plainTextToken)->postJson("/api/v1/projects/{$other->project_id}/environments/production/services", ['kind' => 'database', 'engine' => 'valkey', 'server_id' => $server->id, 'name' => 'x'])
        ->assertNotFound();
    $this->withToken($token->plainTextToken)->postJson('/api/v1/projects/not-a-ulid/environments/staging/services', [])->assertNotFound();
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
