<?php

use Falak\Databases\Application\EngineInventory;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Identity\Contracts\Role;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Events\ServerDeleted;
use Falak\Servers\Events\ServerProvisioned;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
});

it('derives engines from the server stack with distro default versions', function () {
    $pg = databases_engine($this->organization, 'postgresql');
    $maria = databases_engine($this->organization, 'mariadb', ServerType::Database);

    expect($pg->engine)->toBe(Engine::PostgreSql)
        ->and($pg->version)->toBe('16')
        ->and($pg->version_source)->toBe('default')
        ->and($pg->port)->toBe(5432)
        ->and($pg->dedicated)->toBeFalse()
        ->and($maria->engine)->toBe(Engine::MariaDb)
        ->and($maria->engine->protocol())->toBe('mysql')
        ->and($maria->version)->toBe('10.11')
        ->and($maria->dedicated)->toBeTrue()
        ->and($maria->port)->toBe(3306);
});

it('prefers the version reported in agent facts', function () {
    $server = databases_server($this->organization, 'postgresql');
    Agent::factory()->create([
        'organization_id' => $this->organization->id,
        'server_id' => $server->id,
        'facts' => ['os' => ['id' => 'ubuntu', 'version' => '24.04'], 'runtimes' => ['postgresql' => ['17.2']]],
    ]);

    $engine = app(EngineInventory::class)->sync($server->id);

    expect($engine->version)->toBe('17')->and($engine->version_source)->toBe('facts')
        ->and(EngineInventory::normalizeVersion(Engine::MySql, '8.4.3-0ubuntu'))->toBe('8.4');
});

it('ignores servers without a database engine and syncs on provisioning', function () {
    $web = databases_server($this->organization, '', ServerType::Web, ['stack' => []]);
    expect(app(EngineInventory::class)->sync($web->id))->toBeNull();

    $server = databases_server($this->organization, 'mysql', ServerType::Database);
    event(new ServerProvisioned($server->id, $this->organization->id, 'db', $server->name));

    expect(DatabaseServer::query()->where('server_id', $server->id)->first()?->engine)->toBe(Engine::MySql);
});

it('lists the organization database servers', function () {
    databases_engine($this->organization, 'postgresql');
    databases_server($this->organization, 'mysql', ServerType::Database);
    [, $other] = memberOf();
    databases_engine($other, 'mysql');

    $this->get('/databases')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Databases/Index', false)
        ->has('servers', 2));
});

it('pins a manual version and returns to detection', function () {
    $engine = databases_engine($this->organization, 'postgresql');

    $this->put("/databases/servers/{$engine->id}", ['version' => '17', 'port' => 6432])->assertSessionHasNoErrors();
    expect($engine->refresh())->version->toBe('17')->version_source->toBe('manual')->port->toBe(6432);

    app(EngineInventory::class)->sync($engine->server_id);
    expect($engine->refresh()->version)->toBe('17');

    $this->put("/databases/servers/{$engine->id}", ['version' => null, 'port' => 5432])->assertSessionHasNoErrors();
    expect($engine->refresh())->version->toBe('16')->version_source->toBe('default');

    $this->put("/databases/servers/{$engine->id}", ['version' => '9.6', 'port' => 5432])->assertSessionHasErrors('version');
});

it('forgets deleted servers but keeps backup history', function () {
    $engine = databases_engine($this->organization, 'postgresql');
    $db = databases_active_db($engine);
    $provider = databases_provider($this->organization);
    $backup = Backup::query()->create([
        'organization_id' => $this->organization->id, 'database_id' => $db->id, 'database_server_id' => $engine->id, 'server_id' => $engine->server_id,
        'server_name' => $engine->server_name, 'database_name' => 'app', 'engine' => Engine::PostgreSql, 'storage_provider_id' => $provider->id,
        'object_key' => 'k', 'compression' => Compression::Gzip, 'trigger' => 'manual', 'status' => BackupStatus::Succeeded, 'sha256' => str_repeat('a', 64),
    ]);
    $running = $backup->replicate()->fill(['status' => BackupStatus::Running, 'sha256' => null]);
    $running->save();

    event(new ServerDeleted($engine->server_id, $this->organization->id, 'app', $engine->server_name));

    expect(DatabaseServer::query()->find($engine->id))->toBeNull()
        ->and($backup->refresh()->status)->toBe(BackupStatus::Succeeded)
        ->and($running->refresh()->status)->toBe(BackupStatus::Failed);
});

it('hides other organizations database servers', function () {
    [, $other] = memberOf();
    $engine = databases_engine($other, 'mysql');

    $this->get("/databases/servers/{$engine->id}")->assertNotFound();
});
