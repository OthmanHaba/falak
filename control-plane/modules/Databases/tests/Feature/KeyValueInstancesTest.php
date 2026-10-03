<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Kiln\Databases\Application\EngineInventory;
use Kiln\Databases\Contracts\Data\DatabaseConsumer;
use Kiln\Databases\Contracts\DatabaseConnections;
use Kiln\Databases\Contracts\DatabaseDirectory;
use Kiln\Databases\Contracts\DatabaseProvisioner;
use Kiln\Databases\Domain\Enums\Engine;
use Kiln\Databases\Domain\Enums\EngineKind;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Databases\Events\DatabaseCreated;
use Kiln\Databases\Events\DatabaseDeleted;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Models\MachineInspection;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

/*
 * Redis and Valkey as Databases engines (v0.7.0 phase 1): one instance per service, redis-server@kiln-<name>, ports
 * 6380–6479, a single `default` user holding requirepass.
 */

function kv_server(object $test, string $cache = 'redis', array $features = ['db.redis'], ?string $database = 'postgresql', ServerType $type = ServerType::App): DatabaseServer
{
    $server = databases_server($test->organization, (string) $database, $type, ['stack' => array_filter(['database' => $database, 'cache' => $cache])]);
    Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $test->organization->id, 'facts' => ['features' => $features, 'memory_bytes' => 2 * 1024 ** 3]]);
    app(EngineInventory::class)->sync($server->id);

    return DatabaseServer::query()->where('server_id', $server->id)->where('engine', $cache)->firstOrFail();
}

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
});

it('knows the key-value engines and their kind', function () {
    expect(Engine::Redis->kind())->toBe(EngineKind::KeyValue)
        ->and(Engine::PostgreSql->kind())->toBe(EngineKind::Sql)
        ->and(Engine::fromStack('valkey'))->toBe(Engine::Valkey)
        ->and(Engine::Redis->isMysqlFamily())->toBeFalse()
        ->and(Engine::Valkey->protocol())->toBe('valkey')
        ->and(Engine::Redis->defaultPort())->toBe(6379)
        ->and(Engine::Redis->driver())->toBe('redis')
        ->and(Engine::Redis->reservedNames())->toBe(['default', 'kiln'])
        ->and(Engine::Redis->privileges())->toBe([])
        ->and(EngineKind::KeyValue->values())->toBe(['redis', 'valkey']);
});

it('registers both engines of an app server, one row per engine, with the version from facts', function () {
    $server = databases_server($this->organization, 'postgresql', ServerType::App, ['stack' => ['database' => 'postgresql', 'cache' => 'redis']]);
    Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $this->organization->id, 'facts' => ['runtimes' => ['redis' => ['7.0.15']], 'os' => ['id' => 'ubuntu', 'version' => '24.04']]]);

    $rows = app(EngineInventory::class)->syncOrganization($this->organization->id);

    expect(collect($rows)->map(fn (DatabaseServer $row) => $row->engine->value)->sort()->values()->all())->toBe(['postgresql', 'redis']);
    $redis = DatabaseServer::query()->where('server_id', $server->id)->where('engine', 'redis')->firstOrFail();
    expect($redis->version)->toBe('7.0')->and($redis->version_source)->toBe('facts')->and($redis->port)->toBe(6379)
        ->and(app(EngineInventory::class)->sync($server->id)->engine)->toBe(Engine::PostgreSql)
        ->and(app(EngineInventory::class)->sync($server->id, Engine::Redis)->id)->toBe($redis->id);

    // A cache server's engine is dedicated; the distro version applies without facts.
    $cache = databases_server($this->organization, 'postgresql', ServerType::Cache, ['stack' => ['cache' => 'valkey']]);
    Agent::factory()->create(['server_id' => $cache->id, 'organization_id' => $this->organization->id, 'facts' => ['os' => ['id' => 'ubuntu', 'version' => '26.04']]]);
    $valkey = app(EngineInventory::class)->sync($cache->id, Engine::Valkey);
    expect($valkey->dedicated)->toBeTrue()->and($valkey->version)->toBe('8.1')->and($valkey->version_source)->toBe('default')
        ->and(app(EngineInventory::class)->sync($cache->id))->toBeNull();
});

it('creates an instance from the canvas provisioner: port, settings, default user and db.redis.apply', function () {
    Event::fake([DatabaseCreated::class]);
    $engine = kv_server($this);

    $data = app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'redis', 'cache', $this->user->id, ['eviction' => 'allkeys-lru']);

    expect($data->engine)->toBe('redis')->and($data->port)->toBe(6380)->and($data->status)->toBe('pending')->and($data->maxMemoryMb)->toBe(128)->and($data->isKeyValue())->toBeTrue();

    $apply = $this->agents->last('db.redis.apply');
    expect(databases_schema_errors($apply))->toBe([])
        ->and($apply['payload'])->toMatchArray(['engine' => 'redis', 'name' => 'cache', 'port' => 6380, 'bind' => ['127.0.0.1'], 'maxmemory_mb' => 128, 'eviction' => 'allkeys-lru', 'persistence' => 'rdb'])
        ->and($apply['payload']['password'])->toMatch('/^[A-Za-z0-9]{32}$/');

    $user = DatabaseUser::query()->where('database_server_id', $engine->id)->firstOrFail();
    expect($user->password)->toBe($apply['payload']['password'])->and($user->command_id)->toBe($apply['handle']->id);

    $this->agents->succeed($apply['handle'], ['changed' => true, 'restarted' => true, 'port' => 6380]);

    expect(Database::query()->findOrFail($data->id)->status->value)->toBe('active')
        ->and($user->refresh()->status->value)->toBe('active');
    Event::assertDispatched(DatabaseCreated::class, fn (DatabaseCreated $e) => $e->databaseId === $data->id && $e->engine === 'redis');

    // The next instance takes the next free port, skipping what the machine check saw listening.
    MachineInspection::query()->create(['server_id' => $engine->server_id, 'purpose' => 'check', 'status' => 'finished', 'report' => ['listeners' => [['port' => 6381, 'process' => 'memcached']]]]);
    $second = app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'redis', 'queue');
    expect($second->port)->toBe(6382);
});

it('refuses agents without db.redis, bad names, wrong engines, settings over the RAM and backups', function () {
    $old = kv_server($this, features: ['db.containers']);

    expect(fn () => app(DatabaseProvisioner::class)->create($this->organization->id, $old->server_id, 'redis', 'cache'))
        ->toThrow(ValidationException::class, "Update the agent on {$old->server_name} first");
    $this->agents->assertNothingDispatched('db.redis.apply');

    $engine = kv_server($this);

    foreach (['Cache', 'default', 'a.b', str_repeat('a', 42)] as $name) {
        expect(fn () => app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'redis', $name))->toThrow(ValidationException::class);
    }

    expect(fn () => app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'valkey', 'cache'))
        ->toThrow(ValidationException::class, 'runs Redis, not Valkey')
        ->and(fn () => app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'redis', 'big', null, ['maxmemory_mb' => 4096]))
        ->toThrow(ValidationException::class, 'between 16 and 1536 MB')
        ->and(fn () => app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'redis', 'odd', null, ['eviction' => 'lru']))
        ->toThrow(ValidationException::class, 'Unknown eviction policy');

    $data = app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'redis', 'cache');
    $this->agents->succeed($this->agents->last('db.redis.apply')['handle'], ['changed' => true, 'restarted' => true, 'port' => 6380]);
    $provider = databases_provider($this->organization);

    $this->post("/databases/databases/{$data->id}/backups", ['storage_provider_id' => $provider->id])
        ->assertSessionHasErrors(['database' => 'Backups of Redis instances are not supported yet (coming in a later release).']);
    $this->post("/databases/servers/{$engine->id}/schedules", ['name' => 'nightly', 'storage_provider_id' => $provider->id, 'database_ids' => [$data->id], 'cron' => '0 3 * * *'])
        ->assertSessionHasErrors('database_ids');
    $this->post("/databases/servers/{$engine->id}/users", ['username' => 'extra', 'grants' => []])->assertSessionHasErrors('username');
    $this->agents->assertNothingDispatched('db.backup');
});

it('rotates the default password by re-applying the instance, and reveals it', function () {
    $engine = kv_server($this);
    $data = app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'redis', 'cache');
    $this->agents->succeed($this->agents->last('db.redis.apply')['handle'], ['changed' => true, 'restarted' => true, 'port' => 6380]);
    $user = DatabaseUser::query()->where('database_server_id', $engine->id)->firstOrFail();

    $this->post("/databases/users/{$user->id}/password", ['password' => 'has spaces and "quotes"'])->assertSessionHasErrors('password');
    $this->post("/databases/users/{$user->id}/password", ['password' => 'New-Pass.word_1234'])->assertSessionHasNoErrors();

    $apply = $this->agents->last('db.redis.apply');
    expect($apply['payload']['password'])->toBe('New-Pass.word_1234')
        ->and($apply['handle']->idempotencyKey)->toBe("db.redis.apply:{$data->id}:2")
        ->and($user->refresh()->status->value)->toBe('pending');
    $this->agents->assertNothingDispatched('db.user.apply');

    $this->agents->succeed($apply['handle'], ['changed' => true, 'restarted' => true, 'port' => 6380]);
    expect($user->refresh()->status->value)->toBe('active')
        ->and(Database::query()->findOrFail($data->id)->status->value)->toBe('active');

    $this->postJson("/databases/users/{$user->id}/reveal")->assertOk()->assertJson(['password' => 'New-Pass.word_1234']);

    // A failed re-apply keeps the instance active and says why.
    $this->post("/databases/users/{$user->id}/password", [])->assertSessionHasNoErrors();
    $this->agents->fail($this->agents->last('db.redis.apply')['handle'], 'port 6380 is in use by memcached (pid 3)');
    expect(Database::query()->findOrFail($data->id))->status->value->toBe('active')
        ->status_message->toBe('Apply failed: port 6380 is in use by memcached (pid 3)')
        ->and($user->refresh()->status->value)->toBe('failed');
});

it('updates memory, eviction and persistence and re-applies', function () {
    $engine = kv_server($this);
    $data = app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'redis', 'cache');
    $this->agents->succeed($this->agents->last('db.redis.apply')['handle'], ['changed' => true, 'restarted' => true, 'port' => 6380]);
    $count = count($this->agents->dispatched('db.redis.apply'));

    $this->putJson("/databases/databases/{$data->id}/settings", ['maxmemory_mb' => 256, 'persistence' => 'aof'])
        ->assertOk()->assertJsonPath('data.settings', ['maxmemory_mb' => 256, 'eviction' => 'noeviction', 'persistence' => 'aof']);
    expect($this->agents->last('db.redis.apply')['payload'])->toMatchArray(['maxmemory_mb' => 256, 'persistence' => 'aof']);

    // Unchanged settings send nothing.
    $this->putJson("/databases/databases/{$data->id}/settings", ['maxmemory_mb' => 256])->assertOk();
    expect($this->agents->dispatched('db.redis.apply'))->toHaveCount($count + 1);

    $this->putJson("/databases/databases/{$data->id}/settings", ['persistence' => 'both'])->assertUnprocessable();
});

it('deletes an instance with db.redis.remove, its default user with it', function () {
    Event::fake([DatabaseDeleted::class]);
    $engine = kv_server($this, 'valkey');
    $data = app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'valkey', 'sessions');
    $this->agents->succeed($this->agents->last('db.redis.apply')['handle'], ['changed' => true, 'restarted' => true, 'port' => 6380]);

    app(DatabaseProvisioner::class)->delete($data->id);
    $remove = $this->agents->last('db.redis.remove');
    expect($remove['payload'])->toBe(['engine' => 'valkey', 'name' => 'sessions'])->and(databases_schema_errors($remove))->toBe([]);

    $this->agents->succeed($remove['handle'], ['changed' => true]);
    expect(Database::query()->find($data->id))->toBeNull()
        ->and(DatabaseUser::query()->where('database_server_id', $engine->id)->exists())->toBeFalse();
    Event::assertDispatched(DatabaseDeleted::class);
});

it('exposes REDIS_* variables to native consumers on the server and explains why others cannot connect', function () {
    $engine = kv_server($this);
    $data = app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'redis', 'cache');
    $password = $this->agents->last('db.redis.apply')['payload']['password'];
    $connections = app(DatabaseConnections::class);

    expect($connections->variables($data->id))->toBe([
        'REDIS_CLIENT' => 'phpredis',
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_PORT' => '6380',
        'REDIS_PASSWORD' => $password,
        'REDIS_URL' => "redis://default:{$password}@127.0.0.1:6380",
    ])->and($connections->keysFor('redis'))->toBe(DatabaseConnections::REDIS_KEYS)
        ->and($connections->keysFor('postgresql'))->toBe(DatabaseConnections::KEYS)
        ->and($connections->hostKeysFor('valkey'))->toBe(['REDIS_URL', 'REDIS_HOST']);

    expect($connections->unreachable($data->id, new DatabaseConsumer('shop', [$engine->server_id], false)))->toBeNull()
        ->and($connections->unreachable($data->id, new DatabaseConsumer('shop', [$engine->server_id], true)))->toContain('runs in a container')
        ->and($connections->unreachable($data->id, new DatabaseConsumer('shop', [$engine->server_id, '01j9zq4n8v2m6r0t3w5y7b9d1f'], false)))->toContain('accepts connections from that server only');

    expect(app(DatabaseDirectory::class)->forServer($engine->server_id)[0]->port)->toBe(6380);
});

it('shows the instance panel with key-value options and its own port', function () {
    $engine = kv_server($this);
    $data = app(DatabaseProvisioner::class)->create($this->organization->id, $engine->server_id, 'redis', 'cache');

    $this->getJson("/databases/databases/{$data->id}")->assertOk()
        ->assertJsonPath('data.server.kind', 'key_value')
        ->assertJsonPath('data.database.port', 6380)
        ->assertJsonPath('data.connection.port', 6380)
        ->assertJsonPath('data.users.0.username', 'default')
        ->assertJsonPath('data.options.max_memory_mb', 1536)
        ->assertJsonPath('data.options.persistences', ['rdb', 'aof', 'none'])
        ->assertJsonPath('data.restore_targets', []);
});
