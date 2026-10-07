<?php

use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Projects\Domain\Models\Service;
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

it('creates a database container with a user through the Databases contract and places it immediately', function () {
    $agents = FakeAgentGateway::install();
    $server = databases_server($this->organization);

    $response = $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'postgresql', 'server_id' => $server->id, 'name' => 'orders', 'version' => '16', 'memory_mb' => 1024, 'disk_gb' => 20, 'x' => 10, 'y' => 20])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'database')
        ->assertJsonPath('data.name', 'orders')
        ->assertJsonPath('data.icon', 'postgresql')
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.subtitle', "PostgreSQL 16 · 1024 MB · {$server->name}")
        ->assertJsonPath('data.position', ['x' => 10, 'y' => 20]);

    $database = Database::query()->sole();
    $instance = DatabaseInstance::query()->sole();
    $create = $agents->last('db.instance.create');

    // The container joins the staging environment's network, where the environment's sites reach it by name.
    expect($response->json('data.ref_id'))->toBe($database->id)
        ->and($instance->environment_id)->toBe($this->staging->id)
        ->and($create['payload']['instance'])->toMatchArray([
            'id' => $instance->id,
            'engine' => 'postgres',
            'version' => '16',
            'memory_bytes' => 1024 * 1024 ** 2,
            'network' => "falak-env-{$this->staging->id}",
            'aliases' => ["falak-db-{$instance->id}"],
            'host_port' => $instance->host_port,
            'volume_id' => $instance->volume_id,
        ])
        ->and($agents->last('volume.create')['payload']['size_bytes'])->toBe(20 * 1024 ** 3)
        ->and(app(DatabaseConnections::class)->variables($database->id)['DB_USERNAME'])->toBe('orders');

    // db.create runs once the container does; converging later does not move or duplicate the card.
    $agents->succeed($create['handle'], ['changed' => true, 'container_id' => 'abc', 'image_digest' => 'sha256:'.str_repeat('b', 64), 'health' => 'healthy']);
    expect($agents->last('db.create')['payload'])->toMatchArray(['instance' => $instance->id, 'name' => 'orders']);
    $agents->succeed($agents->last('db.create')['handle'], ['changed' => true]);
    expect(Service::query()->sole()->environment_id)->toBe($this->staging->id)
        ->and($database->refresh()->status->value)->toBe('active');
});

it('rejects unsupported database engines, versions and foreign servers', function () {
    FakeAgentGateway::install();
    $server = databases_server($this->organization);

    $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'memcached', 'server_id' => $server->id, 'name' => 'cache'])
        ->assertUnprocessable()->assertJsonValidationErrors(['engine']);
    $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'postgresql', 'server_id' => $server->id, 'name' => 'orders', 'version' => '9.6'])
        ->assertUnprocessable()->assertJsonValidationErrors(['version']);
    $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'mysql', 'server_id' => str_repeat('0', 26), 'name' => 'orders'])
        ->assertUnprocessable()->assertJsonValidationErrors(['server_id']);
    $this->postJson("{$this->base}/services", ['kind' => 'queue'])->assertUnprocessable()->assertJsonValidationErrors(['kind']);

    expect(Database::query()->count())->toBe(0)->and(DatabaseInstance::query()->count())->toBe(0);
});

it('creates a Redis container from the canvas: card, REDIS_* keys and references by where the site runs', function () {
    $agents = FakeAgentGateway::install();
    $server = databases_server($this->organization);

    $this->postJson("{$this->base}/services", ['kind' => 'database', 'engine' => 'redis', 'server_id' => $server->id, 'name' => 'cache', 'memory_mb' => 256, 'eviction' => 'allkeys-lru'])
        ->assertCreated()
        ->assertJsonPath('data.icon', 'redis')
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.subtitle', "Redis 8 · 256 MB · {$server->name}")
        ->assertJsonPath('data.volumes.0.detail', 'data');

    $instance = DatabaseInstance::query()->sole();
    $create = $agents->last('db.instance.create');
    expect($create['payload']['instance'])->toMatchArray(['engine' => 'redis', 'memory_bytes' => 256 * 1024 ** 2, 'settings' => ['eviction' => 'allkeys-lru']]);
    $agents->succeed($create['handle'], ['changed' => true, 'container_id' => 'abc', 'image_digest' => 'sha256:'.str_repeat('b', 64), 'health' => 'healthy']);

    $this->getJson("{$this->base}/variables")->assertOk()
        ->assertJsonPath('data.services.0.keys', DatabaseConnections::REDIS_KEYS);

    // Native on the server: loopback and the host port. A container of the environment: its name and the engine's port.
    $password = $instance->refresh()->root_password;
    $web = projects_site($this->organization, 'Web', [], $this->staging, [$server]);
    $result = app(VariableReferences::class)->resolve($this->staging->id, $web->id, ['REDIS_URL' => '${{ cache.REDIS_URL }}', 'REDIS_PORT' => '${{ cache.REDIS_PORT }}']);
    expect($result->errors)->toBe([])
        ->and($result->variables)->toBe(['REDIS_URL' => "redis://default:{$password}@127.0.0.1:{$instance->host_port}", 'REDIS_PORT' => (string) $instance->host_port]);

    $box = projects_site($this->organization, 'Box', [], $this->staging, [$server], ['runtime' => 'docker', 'framework' => 'docker', 'php_version' => null]);
    $result = app(VariableReferences::class)->resolve($this->staging->id, $box->id, ['REDIS_HOST' => '${{ cache.REDIS_HOST }}', 'REDIS_PORT' => '${{ cache.REDIS_PORT }}']);
    expect($result->errors)->toBe([])
        ->and($result->variables)->toBe(['REDIS_HOST' => "falak-db-{$instance->id}", 'REDIS_PORT' => '6379']);

    // A site on another server gets the reason instead of a host it cannot reach.
    $other = projects_site($this->organization, 'Elsewhere', [], $this->staging, [sites_server($this->organization->id, ['name' => 'web-9'])]);
    $result = app(VariableReferences::class)->resolve($this->staging->id, $other->id, ['REDIS_HOST' => '${{ cache.REDIS_HOST }}']);
    expect($result->errors[0] ?? '')->toContain('cache.REDIS_HOST cannot be used here');
});

it('creates a Valkey container through the API with a token', function () {
    $agents = FakeAgentGateway::install();
    $server = databases_server($this->organization);
    $token = $this->user->createToken('cli', ['*']);
    $token->accessToken->forceFill(['organization_id' => $this->organization->id])->save();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)->postJson("/api/v1/projects/{$this->staging->project_id}/environments/staging/services", ['kind' => 'database', 'engine' => 'valkey', 'server_id' => $server->id, 'name' => 'sessions', 'persistence' => 'aof'])
        ->assertCreated()->assertJsonPath('data.icon', 'valkey')->assertJsonPath('data.name', 'sessions');
    expect($agents->last('db.instance.create')['payload']['instance'])->toMatchArray(['engine' => 'valkey', 'settings' => ['persistence' => 'aof']]);

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
