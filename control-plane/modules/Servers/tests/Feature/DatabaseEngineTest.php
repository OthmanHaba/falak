<?php

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Application\EngineInventory;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Application\Actions\InstallDatabaseEngine;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\DatabaseEngineInstallFailed;

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

it('keeps an engine that is still being installed from other modules, so Databases registers no phantom server', function () {
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'mysql'])->assertSessionHasNoErrors();

    // Databases syncs its inventory from the servers meanwhile (e.g. opening the Databases page).
    expect(app(ServerDirectory::class)->find($this->server->id)->databaseEngine)->toBeNull();
    app(EngineInventory::class)->syncOrganization($this->organization->id);
    expect(DatabaseServer::query()->where('server_id', $this->server->id)->exists())->toBeFalse();

    [$envelope] = servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $envelope['id']);
    expect(app(ServerDirectory::class)->find($this->server->id)->databaseEngine)->toBe('mysql');
});

it('drops an engine row Databases registered for an install that failed, unless it holds databases', function () {
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'mysql'])->assertSessionHasNoErrors();
    // A row registered before engines were hidden while installing.
    $row = DatabaseServer::query()->create(['server_id' => $this->server->id, 'organization_id' => $this->organization->id, 'server_name' => 'app-2', 'engine' => 'mysql', 'version' => '8.0', 'version_source' => 'default', 'dedicated' => false, 'port' => 3306]);

    [$envelope] = servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $envelope['id'], 100, 'E: Unable to locate package mysql-server');
    expect(DatabaseServer::query()->whereKey($row->id)->exists())->toBeFalse();

    // With databases in it, the row stays.
    $row = DatabaseServer::query()->create(['server_id' => $this->server->id, 'organization_id' => $this->organization->id, 'server_name' => 'app-2', 'engine' => 'mysql', 'version' => '8.0', 'version_source' => 'default', 'dedicated' => false, 'port' => 3306]);
    Database::query()->create(['database_server_id' => $row->id, 'organization_id' => $this->organization->id, 'server_id' => $this->server->id, 'name' => 'shop', 'status' => 'active']);
    event(new DatabaseEngineInstallFailed($this->server->id, $this->organization->id, 'mysql'));
    expect(DatabaseServer::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('checks for a running install on the current row, not on the caller’s copy', function () {
    $stale = Server::query()->findOrFail($this->server->id);
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'postgresql'])->assertSessionHasNoErrors();

    expect(fn () => app(InstallDatabaseEngine::class)($stale, 'mariadb'))
        ->toThrow(ValidationException::class, 'A database engine is already being installed on this server.');
    expect($this->server->refresh()->stack->database)->toBe('postgresql');
});

it('releases the claim when the plan cannot be dispatched', function () {
    $this->mock(AgentGateway::class)->shouldReceive('dispatch')->andThrow(AgentUnavailable::forServer($this->server->id));

    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'postgresql'])->assertSessionHasErrors(['server' => 'The server agent is not connected. Reinstall the agent first.']);
    $server = $this->server->refresh();
    expect($server->stack->database)->toBeNull()->and($server->engine_command_id)->toBeNull();
});

it('installs Redis next to the database engine and registers it in Databases; Valkey only where the OS has it', function () {
    // Ubuntu 22.04 has no valkey-server (24.04 has it in noble-updates, 26.04 and Debian 13 too).
    $this->server->forceFill(['os' => 'ubuntu 22.04'])->save();
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'valkey'])
        ->assertSessionHasErrors(['engine' => 'Valkey is not available on Ubuntu 22.04.']);

    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'postgresql'])->assertSessionHasNoErrors();
    // One install at a time.
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'redis'])
        ->assertSessionHasErrors(['engine' => 'A database engine is already being installed on this server.']);
    [$envelope] = servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $envelope['id']);

    $this->server->refresh();
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'redis'])->assertSessionHasNoErrors();
    $server = $this->server->refresh();
    expect($server->stack->cache)->toBe('redis')->and($server->engine_install_kind)->toBe('cache')
        ->and(app(ServerDirectory::class)->find($server->id))->cacheEngine->toBeNull()->databaseEngine->toBe('postgresql');

    [$envelope] = servers_poll($this->agent['headers']);
    expect($envelope['payload']['apt']['packages'])->toContain('redis-server', 'postgresql');
    servers_finish($this->agent['headers'], $envelope['id']);

    expect($server->refresh()->engine_command_id)->toBeNull()
        ->and($server->engine_install_kind)->toBeNull()
        ->and(app(ServerDirectory::class)->find($server->id)->cacheEngine)->toBe('redis')
        ->and(DatabaseServer::query()->where('server_id', $server->id)->pluck('engine')->map->value->sort()->values()->all())->toBe(['postgresql', 'redis']);

    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'valkey'])
        ->assertSessionHasErrors('engine');

    $this->get("/servers/{$server->id}/settings")->assertInertia(fn ($page) => $page
        ->where('cache.engine', 'redis')->where('cache.installing', false)->where('cache.allowed', true)
        ->where('cache.options', [['value' => 'redis', 'label' => 'Redis']]));
});

it('takes a cache engine back out of the stack when its plan fails, leaving the database engine', function () {
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'redis'])->assertSessionHasNoErrors();
    [$envelope] = servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $envelope['id'], 100, 'E: Unable to locate package redis-server');

    $server = $this->server->refresh();
    expect($server->stack->cache)->toBeNull()->and($server->engine_command_id)->toBeNull()->and($server->engine_install_kind)->toBeNull()
        ->and(DatabaseServer::query()->where('server_id', $server->id)->exists())->toBeFalse();
});

it('refuses a second engine, unsupported engines and server types without databases', function () {
    $this->post("/servers/{$this->server->id}/database-engine", ['engine' => 'memcached'])->assertSessionHasErrors('engine');

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
