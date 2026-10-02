<?php

use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Domain\Models\Server;

require_once __DIR__.'/../Support/helpers.php';

/*
 * Adding a database engine to a provisioned server: the engine joins the stack and the provisioning plan converges
 * with it (same packages and service as at creation); its outcome registers the engine in Databases or takes it out.
 */

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/kiln-ca-test']);
    [$this->user, $this->organization] = actingAsMember(Role::Developer);

    $this->post('/servers', ['name' => 'app-2', 'type' => 'app', 'provider' => 'custom', 'stack' => ['php' => ['runtime' => 'frankenphp', 'versions' => ['8.4'], 'default' => '8.4'], 'docker' => true]]);
    $this->server = Server::query()->firstOrFail();
    $this->agent = servers_enroll_agent($this->server, ['memory_bytes' => 4 * 1024 ** 3]);
    servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $this->server->refresh()->provision_command_id);
    servers_poll($this->agent['headers']); // ssh key syncs
});

it('installs a database engine on a provisioned server and registers it in Databases', function () {
    expect($this->server->refresh()->stack->database)->toBeNull();

    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'postgresql'])->assertSessionHasNoErrors();

    $server = $this->server->refresh();
    expect($server->stack->database)->toBe('postgresql')
        ->and($server->engine_command_id)->not->toBeNull();

    [$envelope] = servers_poll($this->agent['headers']);
    expect($envelope['type'])->toBe('provision.apply')
        ->and($envelope['id'])->toBe($server->engine_command_id)
        ->and($envelope['payload']['apt']['packages'])->toContain('postgresql', 'postgresql-contrib')
        ->and(collect($envelope['payload']['services'])->firstWhere('name', 'postgresql'))->toMatchArray(['enabled' => true, 'state' => 'started'])
        ->and(app(ProtocolSchemas::class)->validateCommand('provision.apply', ProtocolSchemas::toJson($envelope['payload'])))->toBe([]);

    servers_finish($this->agent['headers'], $envelope['id']);

    expect($server->refresh()->engine_command_id)->toBeNull()
        ->and($server->stack->database)->toBe('postgresql')
        ->and($server->status->value)->toBe('active')
        ->and(DatabaseServer::query()->where('server_id', $server->id)->value('engine')?->value)->toBe('postgresql');
});

it('takes the engine back out of the stack when the plan fails', function () {
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'mysql'])->assertSessionHasNoErrors();
    [$envelope] = servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $envelope['id'], 100, 'E: Unable to locate package mysql-server');

    $server = $this->server->refresh();
    expect($server->stack->database)->toBeNull()
        ->and($server->engine_command_id)->toBeNull()
        ->and($server->status->value)->toBe('active')
        ->and(DatabaseServer::query()->where('server_id', $server->id)->exists())->toBeFalse();

    // A later attempt is allowed again.
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'postgresql'])->assertSessionHasNoErrors();
});

it('refuses a second engine, unsupported engines and server types without databases', function () {
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'redis'])->assertSessionHasErrors('engine');

    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'postgresql'])->assertSessionHasNoErrors();
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'mariadb'])
        ->assertSessionHasErrors(['engine' => 'A database engine is already being installed on this server.']);

    [$envelope] = servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $envelope['id']);
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'mariadb'])
        ->assertSessionHasErrors(['engine' => 'The server already runs postgresql.']);

    $this->post('/servers', ['name' => 'web-9', 'type' => 'web', 'provider' => 'custom', 'stack' => ['php' => ['runtime' => 'fpm', 'versions' => ['8.4'], 'default' => '8.4']]]);
    $web = Server::query()->where('name', 'web-9')->firstOrFail();
    $web->forceFill(['status' => 'active'])->save();
    $this->post("/servers/{$web->id}/database-engine", ['engine' => 'postgresql'])
        ->assertSessionHasErrors(['engine' => 'A Web server cannot run a database engine.']);
});

it('is available through the API for members who may update the server', function () {
    $token = $this->user->createToken('cli', ['*'])->plainTextToken;

    $this->withToken($token)->postJson("/api/v1/servers/{$this->server->id}/database-engine", ['engine' => 'postgresql'])
        ->assertStatus(202)
        ->assertJsonPath('data.engine', 'postgresql')
        ->assertJsonPath('data.status', 'installing');

    [$viewer] = actingAsMember(Role::Viewer, $this->organization);
    $this->withToken($viewer->createToken('cli', ['*'])->plainTextToken)
        ->postJson("/api/v1/servers/{$this->server->id}/database-engine", ['engine' => 'postgresql'])->assertForbidden();
});

it('shows the engine section in Settings for app servers', function () {
    $this->get("/servers/{$this->server->id}/settings")->assertOk()
        ->assertInertia(fn ($page) => $page->where('database.engine', null)->where('database.allowed', true)->where('database.installing', false));
});
