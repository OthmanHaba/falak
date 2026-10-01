<?php

use Kiln\Identity\Application\Actions\CreateApiToken;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Http\Channels\ServerChannel;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->owner, $this->organization] = memberOf();
    $this->server = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-1']);
});

it('lets every role view servers', function (Role $role) {
    [$user] = memberOf($this->organization, $role);

    $this->actingAs($user)->get('/servers')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Servers/Index', false)
        ->has('servers', 1)
        ->where('servers.0.name', 'web-1')
        ->where('can.create', $role !== Role::Viewer));

    $this->actingAs($user)->get("/servers/{$this->server->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Servers/Show', false)
        ->where('server.id', $this->server->id)
        ->where('can.update', $role !== Role::Viewer)
        ->where('can.delete', in_array($role, [Role::Owner, Role::Admin], true)));
})->with([Role::Owner, Role::Admin, Role::Developer, Role::Viewer]);

it('forbids viewers from mutating servers', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);

    $this->get('/servers/create')->assertForbidden();
    $this->post('/servers', ['name' => 'x', 'type' => 'web', 'provider' => 'custom'])->assertForbidden();
    $this->patch("/servers/{$this->server->id}", ['name' => 'y'])->assertForbidden();
    $this->post("/servers/{$this->server->id}/reprovision")->assertForbidden();
    $this->post("/servers/{$this->server->id}/php", ['version' => '8.3'])->assertForbidden();
    $this->delete("/servers/{$this->server->id}", ['name' => 'web-1'])->assertForbidden();
    $this->post('/ssh-keys', ['name' => 'k', 'public_key' => 'x'])->assertForbidden();
});

it('only admins and owners can delete', function () {
    [$developer] = memberOf($this->organization, Role::Developer);

    $this->actingAs($developer)->delete("/servers/{$this->server->id}", ['name' => 'web-1'])->assertForbidden();
});

it('hides servers of other organizations (404, not 403)', function () {
    [$outsider] = memberOf();
    $this->actingAs($outsider);

    $this->get("/servers/{$this->server->id}")->assertNotFound();
    $this->patch("/servers/{$this->server->id}", ['name' => 'pwned'])->assertNotFound();
    $this->get("/servers/{$this->server->id}/metrics")->assertNotFound();
    $this->get('/servers')->assertInertia(fn ($page) => $page->has('servers', 0));
    $this->getJson('/servers/search?q=web')->assertExactJson(['data' => []]);
});

it('renames servers with validation', function () {
    $this->actingAs($this->owner)->patch("/servers/{$this->server->id}", ['name' => 'api-1'])->assertSessionHasNoErrors();
    expect($this->server->refresh()->name)->toBe('api-1');

    Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'taken']);
    $this->actingAs($this->owner)->patch("/servers/{$this->server->id}", ['name' => 'taken'])->assertSessionHasErrors('name');
});

it('searches servers for the command palette', function () {
    $this->actingAs($this->owner)->getJson('/servers/search?q=web')->assertOk()->assertJsonPath('data.0.id', $this->server->id);
});

it('renders the create page with provider credentials through the gateway contract', function () {
    $this->actingAs($this->owner)->get('/servers/create')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Servers/Create', false)
        ->has('types', 7)
        ->where('types.0.value', 'app')
        ->has('options.php_versions')
        ->has('credentials', 0));
});

it('returns metrics samples for a range', function () {
    $this->actingAs($this->owner)->getJson("/servers/{$this->server->id}/metrics?range=24h")->assertOk()->assertJsonPath('data', []);
    $this->actingAs($this->owner)->getJson("/servers/{$this->server->id}/metrics?range=7d")->assertUnprocessable();
});

it('exposes servers over the token API with ability checks', function () {
    $token = app(CreateApiToken::class)($this->owner, $this->organization->id, 'cli', ['servers.view'])->plainTextToken;
    $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

    $this->getJson('/api/v1/servers', $headers)->assertOk()->assertJsonPath('data.0.name', 'web-1');
    $this->getJson("/api/v1/servers/{$this->server->id}", $headers)->assertOk()->assertJsonPath('data.stack.php.runtime', 'frankenphp');
    $this->postJson('/api/v1/servers', ['name' => 'x', 'type' => 'web', 'provider' => 'custom'], $headers)->assertForbidden();
    $this->deleteJson("/api/v1/servers/{$this->server->id}", [], $headers)->assertForbidden();
});

it('returns the private address and SSH port for kiln ssh --private', function () {
    $this->server->forceFill(['private_ipv4' => '10.0.0.5', 'ssh_port' => 2222])->save();
    $token = app(CreateApiToken::class)($this->owner, $this->organization->id, 'cli', ['servers.view'])->plainTextToken;

    $this->getJson("/api/v1/servers/{$this->server->id}", ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.private_ipv4', '10.0.0.5')
        ->assertJsonPath('data.ssh_port', 2222);
});

it('creates custom servers over the API and returns the install command', function () {
    $token = app(CreateApiToken::class)($this->owner, $this->organization->id, 'cli', ['*'])->plainTextToken;

    $this->postJson('/api/v1/servers', ['name' => 'api-made', 'type' => 'worker', 'provider' => 'custom'], ['Authorization' => "Bearer {$token}"])
        ->assertCreated()
        ->assertJsonPath('data.type', 'worker')
        ->assertJsonPath('data.status', 'creating')
        ->assertJson(fn ($json) => $json->where('data.install_command', fn ($command) => str_starts_with($command, 'curl -fsSL '))->etc());
});

it('authorizes the servers broadcast channel per organization', function () {
    $channel = app(ServerChannel::class);
    [$outsider] = memberOf();
    [$viewer] = memberOf($this->organization, Role::Viewer);

    expect($channel->join($viewer, $this->server->id))->toBeTrue()
        ->and($channel->join($outsider, $this->server->id))->toBeFalse()
        ->and($channel->join($viewer, '01JNOPE0000000000000000000'))->toBeFalse();
});
