<?php

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\Jobs\RemoveRetiredInstances;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Events\DatabaseCreated;
use Falak\Databases\Events\DatabaseDeleted;
use Falak\Fleet\Events\AgentDatabasesReported;
use Falak\Identity\Contracts\Role;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->server = databases_server($this->organization);
});

function instances_create(object $test, array $data = []): DatabaseInstance
{
    $test->post('/databases/instances', ['engine' => 'postgresql', 'server_id' => $test->server->id, 'name' => 'shop', ...$data])->assertSessionHasNoErrors();

    return DatabaseInstance::query()->latest()->orderByDesc('id')->firstOrFail();
}

it('creates a PostgreSQL container on a new sized volume with a sealed password, TLS and a loopback port', function () {
    $instance = instances_create($this, ['memory_mb' => 1024, 'disk_gb' => 20, 'settings' => ['max_connections' => 200]]);
    $create = $this->agents->last('db.instance.create');
    $volume = Volume::query()->findOrFail($instance->volume_id);

    expect($instance->status)->toBe(InstanceStatus::Pending)
        ->and($instance->engine->value)->toBe('postgresql')
        ->and($instance->version)->toBe('17')
        ->and($instance->image)->toBe('ghcr.io/othmanhaba/falak-postgres:17')
        ->and($instance->hostname)->toBe("falak-db-{$instance->id}")
        ->and($instance->host_port)->toBe(20000)
        ->and($instance->memory_bytes)->toBe(1024 * 1024 ** 2)
        ->and($instance->tls_expires_at)->not->toBeNull()
        ->and($volume->size_limit_bytes)->toBe(20 * 1024 ** 3)
        ->and($volume->protected)->toBeTrue()
        ->and($volume->attachments()->first()->attachable_type)->toBe(AttachableType::Database)
        ->and($this->agents->last('volume.create')['payload']['volume']['kind'])->toBe('sized')
        ->and($create['payload']['instance'])->toMatchArray([
            'id' => $instance->id,
            'engine' => 'postgres',
            'version' => '17',
            'volume_id' => $instance->volume_id,
            'host_port' => 20000,
            'memory_bytes' => 1024 * 1024 ** 2,
            'aliases' => ["falak-db-{$instance->id}"],
        ])
        ->and((array) $create['payload']['instance']['settings'])->toBe(['max_connections' => 200])
        ->and($create['payload']['instance']['tls'])->toHaveKeys(['certificate', 'private_key', 'ca'])
        ->and($create['payload']['password'])->toBe($instance->root_password)
        ->and($create['handle']->idempotencyKey)->toBe("db.instance.create:{$instance->id}")
        ->and(databases_schema_errors($create))->toBe([]);

    // Sealed at rest: neither the password nor anything readable in the row.
    $raw = (string) DB::table('databases_instances')->where('id', $instance->id)->value('root_password');
    expect($raw)->not->toContain($instance->root_password);

    // The default database and its user wait for the container.
    expect(Database::query()->where('database_instance_id', $instance->id)->value('name'))->toBe('shop')
        ->and(DatabaseUser::query()->where('database_instance_id', $instance->id)->value('username'))->toBe('shop')
        ->and($this->agents->dispatched('db.create'))->toBe([])
        ->and($this->agents->dispatched('db.user.apply'))->toBe([]);
});

it('creates the default database and user once the container runs, and records the image digest', function () {
    Event::fake([DatabaseCreated::class]);
    $instance = instances_create($this);
    $digest = 'sha256:'.str_repeat('b', 64);

    $this->agents->succeed($this->agents->last('db.instance.create')['handle'], ['changed' => true, 'container_id' => 'abc', 'image_digest' => $digest, 'health' => 'healthy']);

    $instance->refresh();
    $create = $this->agents->last('db.create');
    $firstApply = $this->agents->last('db.user.apply');

    expect($instance->status)->toBe(InstanceStatus::Active)
        ->and($instance->image_digest)->toBe($digest)
        ->and($instance->health)->toBe('healthy')
        ->and($create['payload'])->toBe(['instance' => $instance->id, 'engine' => 'postgres', 'name' => 'shop'])
        ->and(databases_schema_errors($create))->toBe([])
        // The database doesn't exist yet: the user comes first without grants.
        ->and($firstApply['payload']['grants'])->toBe([])
        ->and($firstApply['payload']['instance'])->toBe($instance->id)
        ->and(databases_schema_errors($firstApply))->toBe([]);

    $this->agents->succeed($create['handle'], ['changed' => true]);

    $grants = $this->agents->last('db.user.apply');
    expect($grants['payload']['grants'])->toBe([['database' => 'shop', 'privileges' => ['ALL PRIVILEGES']]]);
    Event::assertDispatched(DatabaseCreated::class, fn ($e) => $e->name === 'shop' && $e->engine === 'postgresql');
});

it('gives Redis and Valkey containers their keyspace and default user, active with the container', function () {
    Event::fake([DatabaseCreated::class]);
    $instance = instances_create($this, ['engine' => 'valkey', 'name' => 'cache', 'settings' => ['eviction' => 'allkeys-lru', 'persistence' => 'aof']]);
    $create = $this->agents->last('db.instance.create');

    expect($instance->memory_bytes)->toBe(128 * 1024 ** 2)
        ->and($create['payload']['instance']['engine'])->toBe('valkey')
        ->and((array) $create['payload']['instance']['settings'])->toBe(['eviction' => 'allkeys-lru', 'persistence' => 'aof'])
        ->and(databases_schema_errors($create))->toBe([]);

    $this->agents->succeed($create['handle'], ['changed' => true, 'container_id' => 'abc', 'health' => 'healthy']);

    $user = DatabaseUser::query()->where('database_instance_id', $instance->id)->firstOrFail();
    expect($user->username)->toBe('default')
        ->and($user->password)->toBe($instance->refresh()->root_password)
        ->and($user->status)->toBe(ResourceStatus::Active)
        ->and(Database::query()->where('database_instance_id', $instance->id)->value('status'))->toBe(ResourceStatus::Active)
        ->and($this->agents->dispatched('db.create'))->toBe([]);
    Event::assertDispatched(DatabaseCreated::class, fn ($e) => $e->name === 'cache' && $e->engine === 'valkey');
});

it('validates engine versions, memory limits, settings and names', function () {
    $this->post('/databases/instances', ['engine' => 'postgresql', 'server_id' => $this->server->id, 'name' => 'shop', 'version' => '9.6'])->assertSessionHasErrors('version');
    $this->post('/databases/instances', ['engine' => 'mysql', 'server_id' => $this->server->id, 'name' => 'shop', 'memory_mb' => 64])->assertSessionHasErrors('memory_mb');
    $this->post('/databases/instances', ['engine' => 'redis', 'server_id' => $this->server->id, 'name' => 'cache', 'settings' => ['max_connections' => 10]])->assertSessionHasErrors('settings.max_connections');
    $this->post('/databases/instances', ['engine' => 'redis', 'server_id' => $this->server->id, 'name' => 'Cache!'])->assertSessionHasErrors('name');

    expect(DatabaseInstance::query()->count())->toBe(0);
});

it('refuses servers of other organizations', function () {
    [, $other] = actingAsMember(Role::Admin);
    $foreign = databases_server($other);
    actingAsMember(Role::Developer, $this->organization, $this->user);

    $this->post('/databases/instances', ['engine' => 'postgresql', 'server_id' => $foreign->id, 'name' => 'shop'])->assertSessionHasErrors('server_id');
    expect(DatabaseInstance::query()->count())->toBe(0);
});

it('keeps instances apart between organizations', function () {
    [, , $instance] = databases_service($this->organization);
    [, $other] = actingAsMember(Role::Admin);

    expect([403, 404])->toContain($this->get("/databases/instances/{$instance->id}")->status())
        ->toContain($this->post("/databases/instances/{$instance->id}/restart")->status())
        ->toContain($this->delete("/databases/instances/{$instance->id}", ['confirm' => $instance->name])->status());
    expect($this->agents->dispatched())->toBe([]);
    expect($other->id)->not->toBe($this->organization->id);
});

it('allocates distinct host ports per server', function () {
    $first = instances_create($this, ['name' => 'one']);
    $second = instances_create($this, ['name' => 'two', 'engine' => 'redis']);

    expect([$first->host_port, $second->host_port])->toBe([20000, 20001]);
});

it('updates limits and settings and re-applies the container', function () {
    [, , $instance] = databases_service($this->organization);

    $this->put("/databases/instances/{$instance->id}", ['memory_mb' => 2048, 'settings' => ['slow_query_ms' => 250]])->assertSessionHasNoErrors();
    $update = $this->agents->last('db.instance.update');

    expect($update['payload']['instance']['memory_bytes'])->toBe(2048 * 1024 ** 2)
        ->and((array) $update['payload']['instance']['settings'])->toBe(['slow_query_ms' => 250])
        ->and($update['payload']['instance'])->not->toHaveKey('tls')
        ->and(databases_schema_errors($update))->toBe([]);

    $this->agents->fail($update['handle'], 'no space left');
    expect($instance->refresh()->status_message)->toContain('no space left')
        ->and($instance->status)->toBe(InstanceStatus::Active);
});

it('forces TLS when public access is turned on', function () {
    [, , $instance] = databases_service($this->organization);

    $this->put("/databases/instances/{$instance->id}", ['public_access' => true])->assertSessionHasNoErrors();
    $update = $this->agents->last('db.instance.update');

    expect($instance->refresh()->require_tls)->toBeTrue()
        ->and($update['payload']['instance']['publish'])->toBe(['public' => true])
        ->and(((array) $update['payload']['instance']['settings'])['require_tls'])->toBeTrue()
        ->and(databases_schema_errors($update))->toBe([]);
});

it('restarts a container', function () {
    [, , $instance] = databases_service($this->organization);

    $this->post("/databases/instances/{$instance->id}/restart")->assertSessionHasNoErrors();
    $restart = $this->agents->last('db.instance.restart');

    expect($restart['payload'])->toBe(['id' => $instance->id])->and(databases_schema_errors($restart))->toBe([]);

    $this->agents->succeed($restart['handle'], ['changed' => true, 'health' => 'healthy']);
    expect($instance->refresh()->health)->toBe('healthy');
});

it('rotates the superuser password only once the agent confirms', function () {
    [, , $instance] = databases_service($this->organization);
    $before = $instance->root_password;

    $this->post("/databases/instances/{$instance->id}/password")->assertSessionHasNoErrors();
    $rotate = $this->agents->last('db.instance.password');
    $next = $rotate['payload']['password'];

    expect($instance->refresh()->root_password)->toBe($before)
        ->and($next)->not->toBe($before)
        ->and($rotate['payload'])->toMatchArray(['id' => $instance->id, 'engine' => 'postgres'])
        ->and(databases_schema_errors($rotate))->toBe([]);

    $this->agents->succeed($rotate['handle'], ['changed' => true]);
    expect($instance->refresh()->root_password)->toBe($next)->and($instance->next_root_password)->toBeNull();
});

it('keeps the old password when the rotation fails', function () {
    [, , $instance] = databases_service($this->organization);
    $before = $instance->root_password;

    $this->post("/databases/instances/{$instance->id}/password", ['password' => 'a-new-password-1'])->assertSessionHasNoErrors();
    $this->agents->fail($this->agents->last('db.instance.password')['handle'], 'engine refused');

    expect($instance->refresh()->root_password)->toBe($before)
        ->and($instance->next_root_password)->toBeNull()
        ->and($instance->status_message)->toContain('engine refused');
});

it('rotates a Redis default user through the instance password, and its references follow', function () {
    [$database, $user, $instance] = databases_service($this->organization, 'redis', 'cache');

    $this->post("/databases/users/{$user->id}/password")->assertSessionHasNoErrors();
    $rotate = $this->agents->last('db.instance.password');
    $this->agents->succeed($rotate['handle'], ['changed' => true]);

    expect($user->refresh()->password)->toBe($rotate['payload']['password'])
        ->and($instance->refresh()->root_password)->toBe($rotate['payload']['password'])
        ->and(app(DatabaseConnections::class)->variables($database->id)['REDIS_PASSWORD'])->toBe($rotate['payload']['password']);
});

it('deletes a container and keeps its volume unless asked', function () {
    Event::fake([DatabaseDeleted::class]);
    [$database, , $instance] = databases_service($this->organization);
    $volume = Volume::query()->create(['id' => $instance->volume_id, 'organization_id' => $this->organization->id, 'server_id' => $instance->server_id, 'name' => 'db-app', 'kind' => 'sized', 'size_limit_bytes' => 1024 ** 3, 'protected' => true, 'status' => 'active']);
    $volume->attachments()->create(['attachable_type' => AttachableType::Database, 'attachable_id' => $database->id, 'mount_path' => '/var/lib/falak/db']);

    $this->delete("/databases/instances/{$instance->id}", ['confirm' => $instance->name])->assertSessionHasNoErrors();
    $delete = $this->agents->last('db.instance.delete');

    expect($instance->refresh()->status)->toBe(InstanceStatus::Deleting)
        ->and($delete['payload'])->toBe(['id' => $instance->id])
        ->and(databases_schema_errors($delete))->toBe([]);

    $this->agents->succeed($delete['handle'], ['changed' => true]);

    expect(DatabaseInstance::query()->find($instance->id))->toBeNull()
        ->and(Database::query()->find($database->id))->toBeNull()
        ->and($volume->refresh()->attachments()->count())->toBe(0)
        ->and($volume->status->value)->toBe('active')
        ->and($this->agents->dispatched('volume.delete'))->toBe([]);
    Event::assertDispatched(DatabaseDeleted::class, fn ($e) => $e->databaseId === $database->id);
});

it('deletes the data volume with the container when asked', function () {
    [$database, , $instance] = databases_service($this->organization);
    $volume = Volume::query()->create(['id' => $instance->volume_id, 'organization_id' => $this->organization->id, 'server_id' => $instance->server_id, 'name' => 'db-app', 'kind' => 'sized', 'size_limit_bytes' => 1024 ** 3, 'protected' => true, 'status' => 'active']);
    $volume->attachments()->create(['attachable_type' => AttachableType::Database, 'attachable_id' => $database->id, 'mount_path' => '/var/lib/falak/db']);

    $this->delete("/databases/instances/{$instance->id}", ['confirm' => $instance->name, 'delete_volume' => true])->assertSessionHasNoErrors();
    $this->agents->succeed($this->agents->last('db.instance.delete')['handle'], ['changed' => true]);

    expect($this->agents->last('volume.delete')['payload']['volume']['id'])->toBe($volume->id)
        ->and($volume->refresh()->protected)->toBeFalse()
        ->and($volume->status->value)->toBe('deleting');
});

it('upgrades within a major by re-pulling the image in place', function () {
    [, , $instance] = databases_service($this->organization);

    $this->post("/databases/instances/{$instance->id}/upgrade")->assertSessionHasNoErrors();
    $update = $this->agents->last('db.instance.update');

    expect($instance->refresh()->image_digest)->toBeNull()
        ->and($update['payload']['instance'])->not->toHaveKey('digest')
        ->and($update['payload']['instance']['version'])->toBe('17')
        ->and($this->agents->dispatched('db.instance.create'))->toBe([]);
});

it('upgrades Redis majors in place on the same volume', function () {
    [, , $instance] = databases_service($this->organization, 'redis', 'cache', attributes: ['version' => '7.4', 'image' => 'ghcr.io/othmanhaba/falak-redis:7.4']);

    $this->post("/databases/instances/{$instance->id}/upgrade", ['version' => '8'])->assertSessionHasNoErrors();

    expect($this->agents->last('db.instance.update')['payload']['instance'])->toMatchArray(['version' => '8', 'image' => 'ghcr.io/othmanhaba/falak-redis:8', 'volume_id' => $instance->volume_id]);
});

it('never downgrades', function () {
    [, , $instance] = databases_service($this->organization);

    $this->post("/databases/instances/{$instance->id}/upgrade", ['version' => '16'])->assertSessionHasErrors('version');
});

it('upgrades a PostgreSQL major into a new container, copies the data and hands over the name and port', function () {
    [$database, $user, $instance] = databases_service($this->organization, 'postgresql', 'shop', attributes: ['version' => '16', 'image' => 'ghcr.io/othmanhaba/falak-postgres:16', 'environment_id' => strtolower((string) Str::ulid())]);
    $schedule = $instance->schedules()->create(['organization_id' => $this->organization->id, 'storage_provider_id' => databases_provider($this->organization)->id, 'name' => 'nightly', 'cron' => '0 3 * * *']);
    $oldPort = $instance->host_port;
    $oldHostname = $instance->hostname;

    $this->post("/databases/instances/{$instance->id}/upgrade", ['version' => '17'])->assertSessionHasNoErrors();

    $target = DatabaseInstance::query()->where('upgrade_of', $instance->id)->firstOrFail();
    $create = $this->agents->last('db.instance.create');

    expect($instance->refresh()->status)->toBe(InstanceStatus::Upgrading)
        ->and($target->version)->toBe('17')
        ->and($target->root_password)->toBe($instance->root_password)
        ->and($target->volume_id)->not->toBe($instance->volume_id)
        ->and($create['payload']['instance']['id'])->toBe($target->id)
        ->and($create['payload']['instance']['network'])->toBe($instance->network());

    $this->agents->succeed($create['handle'], ['changed' => true, 'container_id' => 'x', 'health' => 'healthy']);
    $upgrade = $this->agents->last('db.instance.upgrade');

    expect($upgrade['payload'])->toMatchArray([
        'mode' => 'major',
        'source' => ['id' => $instance->id, 'engine' => 'postgres'],
        'target' => ['id' => $target->id, 'engine' => 'postgres'],
        'databases' => [['name' => 'shop']],
        'network' => $instance->network(),
        'alias' => $oldHostname,
    ])
        ->and($upgrade['payload']['users'][0])->toMatchArray(['username' => 'shop', 'password' => $user->password, 'grants' => [['database' => 'shop', 'privileges' => ['ALL PRIVILEGES']]]])
        ->and(databases_schema_errors($upgrade))->toBe([])
        ->and($this->agents->dispatched('db.create'))->toBe([]);

    $this->agents->succeed($upgrade['handle'], ['databases' => [['name' => 'shop', 'bytes' => 100]], 'duration_ms' => 10]);

    $target->refresh();
    expect($target->status)->toBe(InstanceStatus::Active)
        ->and($target->hostname)->toBe($oldHostname)
        ->and($target->host_port)->toBe($oldPort)
        ->and($target->upgrade_of)->toBeNull()
        ->and($database->refresh()->database_instance_id)->toBe($target->id)
        ->and($user->refresh()->database_instance_id)->toBe($target->id)
        ->and($schedule->refresh()->database_instance_id)->toBe($target->id)
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Retired)
        ->and($instance->host_port)->toBeNull()
        ->and($instance->retire_at->isFuture())->toBeTrue()
        ->and($this->agents->last('db.instance.update')['payload']['instance'])->toMatchArray(['id' => $target->id, 'host_port' => $oldPort, 'aliases' => [$oldHostname]]);
});

it('leaves the old container running when the major upgrade copy fails', function () {
    [$database, , $instance] = databases_service($this->organization, 'mysql', 'shop', attributes: ['version' => '8.0', 'image' => 'ghcr.io/othmanhaba/falak-mysql:8.0']);

    $this->post("/databases/instances/{$instance->id}/upgrade", ['version' => '8.4'])->assertSessionHasNoErrors();
    $target = DatabaseInstance::query()->where('upgrade_of', $instance->id)->firstOrFail();
    $this->agents->succeed($this->agents->last('db.instance.create')['handle'], ['changed' => true, 'container_id' => 'x']);
    $this->agents->fail($this->agents->last('db.instance.upgrade')['handle'], 'restore failed');

    expect($instance->refresh()->status)->toBe(InstanceStatus::Active)
        ->and($instance->status_message)->toContain('restore failed')
        ->and($target->refresh()->status)->toBe(InstanceStatus::Failed)
        ->and($database->refresh()->database_instance_id)->toBe($instance->id);
});

it('removes retired instances and their volumes after the grace period', function () {
    $retired = databases_instance($this->organization, attributes: ['status' => InstanceStatus::Retired, 'host_port' => null, 'retire_at' => now()->subMinute()]);
    databases_instance($this->organization, attributes: ['status' => InstanceStatus::Retired, 'host_port' => null, 'retire_at' => now()->addHour()]);
    Volume::query()->create(['id' => $retired->volume_id, 'organization_id' => $this->organization->id, 'server_id' => $retired->server_id, 'name' => 'db-old', 'kind' => 'sized', 'size_limit_bytes' => 1024 ** 3, 'protected' => true, 'status' => 'active']);

    (new RemoveRetiredInstances)->handle(app(AgentCommands::class));

    expect($this->agents->dispatched('db.instance.delete'))->toHaveCount(1);
    $this->agents->succeed($this->agents->last('db.instance.delete')['handle'], ['changed' => true]);

    expect(DatabaseInstance::query()->find($retired->id))->toBeNull()
        ->and($this->agents->last('volume.delete')['payload']['volume']['id'])->toBe($retired->volume_id);
});

it('records health from heartbeats and restores lost password files once per throttle window', function () {
    [, , $instance] = databases_service($this->organization, server: $this->server);
    $agent = $this->agents;

    AgentDatabasesReported::dispatch('agent', $this->organization->id, $this->server->id, [['id' => strtoupper($instance->id), 'state' => 'exited', 'health' => 'none', 'secrets_missing' => true]]);
    AgentDatabasesReported::dispatch('agent', $this->organization->id, $this->server->id, [['id' => $instance->id, 'state' => 'exited', 'health' => 'none', 'secrets_missing' => true]]);

    $secrets = $agent->dispatched('db.instance.secrets');
    expect($instance->refresh()->health)->toBe('stopped')
        ->and($secrets)->toHaveCount(1)
        ->and($secrets[0]['payload'])->toMatchArray(['id' => $instance->id, 'engine' => 'postgres', 'password' => $instance->root_password])
        ->and(databases_schema_errors($secrets[0]))->toBe([]);

    AgentDatabasesReported::dispatch('agent', $this->organization->id, $this->server->id, [['id' => $instance->id, 'state' => 'running', 'health' => 'healthy', 'secrets_missing' => false]]);
    expect($instance->refresh()->health)->toBe('healthy');

    // Not reported at all: the container is gone.
    AgentDatabasesReported::dispatch('agent', $this->organization->id, $this->server->id, []);
    expect($instance->refresh()->health)->toBe('missing');
});

it('never puts passwords in the instance page props', function () {
    [, $user, $instance] = databases_service($this->organization);

    $response = $this->get("/databases/instances/{$instance->id}")->assertOk();

    expect($response->getContent())->not->toContain($instance->root_password)->not->toContain($user->password);
});
