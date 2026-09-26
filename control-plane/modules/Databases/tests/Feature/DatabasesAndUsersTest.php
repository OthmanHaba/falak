<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Kiln\Databases\Contracts\DatabaseDirectory;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Databases\Events\DatabaseCreated;
use Kiln\Databases\Events\DatabaseDeleted;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Contracts\ServerType;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->mysql = databases_engine($this->organization, 'mysql', ServerType::Database);
    $this->pg = databases_engine($this->organization, 'postgresql');
});

it('creates a database with db.create and activates it when the agent confirms', function () {
    Event::fake([DatabaseCreated::class]);

    $this->post("/databases/servers/{$this->mysql->id}/databases", ['name' => 'shop'])->assertSessionHasNoErrors();

    $database = Database::query()->where('name', 'shop')->firstOrFail();
    $command = $this->agents->last('db.create');

    expect($database->status)->toBe(ResourceStatus::Pending)
        ->and($database->command_id)->toBe($command['handle']->id)
        ->and($command['payload'])->toBe(['engine' => 'mysql', 'name' => 'shop', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci'])
        ->and($command['handle']->idempotencyKey)->toStartWith("db.create:{$database->id}:")
        ->and(databases_schema_errors($command))->toBe([]);

    $this->agents->succeed($command['handle'], ['changed' => true]);

    expect($database->refresh()->status)->toBe(ResourceStatus::Active);
    Event::assertDispatched(DatabaseCreated::class, fn ($e) => $e->databaseId === $database->id && $e->engine === 'mysql');
});

it('sends postgres databases without mysql charset options', function () {
    $this->post("/databases/servers/{$this->pg->id}/databases", ['name' => 'analytics', 'charset' => 'latin1'])->assertSessionHasNoErrors();

    expect($this->agents->last('db.create')['payload'])->toBe(['engine' => 'postgres', 'name' => 'analytics']);
});

it('creates a user with the database and applies its grant once the database exists', function () {
    $this->post("/databases/servers/{$this->pg->id}/databases", ['name' => 'app', 'user' => ['username' => 'app_user']])->assertSessionHasNoErrors();

    $user = DatabaseUser::query()->where('username', 'app_user')->firstOrFail();
    $firstApply = $this->agents->last('db.user.apply');

    // The database is not there yet: the user is created without grants.
    expect($firstApply['payload']['grants'])->toBe([])
        ->and($firstApply['payload'])->not->toHaveKey('host')
        ->and(strlen($firstApply['payload']['password']))->toBe(32)
        ->and(databases_schema_errors($firstApply))->toBe([]);

    $this->agents->succeed($this->agents->last('db.create')['handle'], ['changed' => true]);

    $secondApply = $this->agents->last('db.user.apply');
    expect($secondApply['handle']->id)->not->toBe($firstApply['handle']->id)
        ->and($secondApply['payload']['grants'])->toBe([['database' => 'app', 'privileges' => ['ALL PRIVILEGES']]])
        ->and($secondApply['handle']->idempotencyKey)->toBe("db.user.apply:{$user->id}:2")
        ->and(databases_schema_errors($secondApply))->toBe([]);

    $this->agents->succeed($secondApply['handle'], ['changed' => true]);
    expect($user->refresh()->status)->toBe(ResourceStatus::Active);
});

it('stores passwords encrypted and only reveals them with permission, audited', function () {
    databases_active_db($this->mysql, 'shop');
    $this->post("/databases/servers/{$this->mysql->id}/users", ['username' => 'shop', 'password' => 'correct-horse-battery', 'grants' => []])->assertSessionHasNoErrors();
    $user = DatabaseUser::query()->where('username', 'shop')->firstOrFail();

    $raw = DB::table('databases_users')->where('id', $user->id)->value('password');
    expect($raw)->not->toContain('correct-horse-battery')
        ->and($user->toArray())->not->toHaveKey('password');

    $this->get("/databases/servers/{$this->mysql->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Databases/Show', false)
        ->where('users.0.username', 'shop')
        ->missing('users.0.password'));
    expect($this->get("/databases/servers/{$this->mysql->id}")->getContent())->not->toContain('correct-horse-battery');

    $this->postJson("/databases/users/{$user->id}/reveal")->assertOk()->assertJson(['password' => 'correct-horse-battery']);
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'databases.user_password_revealed', 'subject_id' => $user->id]);

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->postJson("/databases/users/{$user->id}/reveal")->assertForbidden();
});

it('updates grants and recreates the account when the mysql host changes', function () {
    $shop = databases_active_db($this->mysql, 'shop');
    $blog = databases_active_db($this->mysql, 'blog');
    $this->post("/databases/servers/{$this->mysql->id}/users", ['username' => 'web', 'grants' => [['database_id' => $shop->id]]]);
    $user = DatabaseUser::query()->where('username', 'web')->firstOrFail();
    expect($this->agents->last('db.user.apply')['payload'])->toMatchArray(['host' => '%', 'grants' => [['database' => 'shop', 'privileges' => ['ALL PRIVILEGES']]]]);

    $this->put("/databases/users/{$user->id}", [
        'host' => '10.90.0.%',
        'grants' => [['database_id' => $blog->id, 'privileges' => ['SELECT', 'INSERT']]],
    ])->assertSessionHasNoErrors();

    [$absent, $present] = array_slice($this->agents->dispatched('db.user.apply'), -2);
    expect($absent['payload'])->toBe(['engine' => 'mysql', 'username' => 'web', 'state' => 'absent', 'host' => '%'])
        ->and($present['payload'])->toMatchArray(['host' => '10.90.0.%', 'grants' => [['database' => 'blog', 'privileges' => ['SELECT', 'INSERT']]]])
        ->and(databases_schema_errors($absent))->toBe([])
        ->and(databases_schema_errors($present))->toBe([])
        ->and($user->refresh()->host)->toBe('10.90.0.%');

    $this->put("/databases/users/{$user->id}", ['grants' => [['database_id' => $blog->id, 'privileges' => ['USAGE']]]])->assertSessionHasErrors();
});

it('rejects grants on other servers databases', function () {
    $foreign = databases_active_db($this->pg, 'elsewhere');

    $this->post("/databases/servers/{$this->mysql->id}/users", ['username' => 'web', 'grants' => [['database_id' => $foreign->id]]])
        ->assertSessionHasErrors('grants');
});

it('rotates passwords', function () {
    $this->post("/databases/servers/{$this->pg->id}/users", ['username' => 'svc', 'grants' => []]);
    $user = DatabaseUser::query()->where('username', 'svc')->firstOrFail();
    $old = $user->password;

    $this->post("/databases/users/{$user->id}/password")->assertSessionHasNoErrors();

    expect($user->refresh()->password)->not->toBe($old)
        ->and($this->agents->last('db.user.apply')['payload']['password'])->toBe($user->password);
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'databases.user_password_rotated']);
});

it('drops databases and users once the agent confirms', function () {
    Event::fake([DatabaseDeleted::class]);
    $db = databases_active_db($this->mysql, 'old');
    $this->post("/databases/servers/{$this->mysql->id}/users", ['username' => 'old_user', 'grants' => [['database_id' => $db->id]]]);
    $user = DatabaseUser::query()->where('username', 'old_user')->firstOrFail();

    $this->delete("/databases/databases/{$db->id}", ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
    $this->delete("/databases/databases/{$db->id}", ['confirm' => 'old'])->assertSessionHasNoErrors();

    $drop = $this->agents->last('db.drop');
    expect($drop['payload'])->toBe(['engine' => 'mysql', 'name' => 'old'])->and($db->refresh()->status)->toBe(ResourceStatus::Deleting);

    $this->agents->succeed($drop['handle'], ['changed' => true]);

    expect(Database::query()->find($db->id))->toBeNull()
        ->and($this->agents->last('db.user.apply')['payload']['grants'])->toBe([]);
    Event::assertDispatched(DatabaseDeleted::class);

    $this->delete("/databases/users/{$user->id}")->assertSessionHasNoErrors();
    $absent = $this->agents->last('db.user.apply');
    expect($absent['payload']['state'])->toBe('absent')->and($user->refresh()->status)->toBe(ResourceStatus::Deleting);

    $this->agents->succeed($absent['handle'], ['changed' => true]);
    expect(DatabaseUser::query()->find($user->id))->toBeNull();
});

it('records failures from the agent', function () {
    $this->post("/databases/servers/{$this->mysql->id}/databases", ['name' => 'broken']);
    $this->agents->fail($this->agents->last('db.create')['handle'], 'ERROR 1044: access denied');

    expect(Database::query()->where('name', 'broken')->first())
        ->status->toBe(ResourceStatus::Failed)
        ->status_message->toContain('access denied');
});

it('validates names, reserved identifiers and duplicates', function () {
    databases_active_db($this->mysql, 'taken');

    $this->post("/databases/servers/{$this->mysql->id}/databases", ['name' => '1bad-name'])->assertSessionHasErrors('name');
    $this->post("/databases/servers/{$this->mysql->id}/databases", ['name' => 'mysql'])->assertSessionHasErrors('name');
    $this->post("/databases/servers/{$this->mysql->id}/databases", ['name' => 'taken'])->assertSessionHasErrors('name');
    $this->post("/databases/servers/{$this->pg->id}/users", ['username' => 'postgres', 'grants' => []])->assertSessionHasErrors('username');
    $this->agents->assertNothingDispatched();
});

it('turns a missing agent into a validation error', function () {
    $this->agents->unavailable($this->mysql->server_id);

    $this->post("/databases/servers/{$this->mysql->id}/databases", ['name' => 'shop'])->assertSessionHasErrors('name');

    expect(Database::query()->where('name', 'shop')->exists())->toBeFalse();
});

it('lets viewers look but not change anything', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);

    $this->get("/databases/servers/{$this->mysql->id}")->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false)->where('can.reveal', false));
    $this->post("/databases/servers/{$this->mysql->id}/databases", ['name' => 'shop'])->assertForbidden();
});

it('exposes databases through the DatabaseDirectory contract', function () {
    $db = databases_active_db($this->pg, 'site_db');
    $siteId = (string) Str::ulid();
    $db->forceFill(['site_id' => $siteId])->save();

    $directory = app(DatabaseDirectory::class);

    expect($directory->forSite($this->organization->id, $siteId))->toHaveCount(1)
        ->and($directory->find($db->id))->engine->toBe('postgresql')->port->toBe(5432)->status->toBe('active')
        ->and($directory->forServer($this->pg->server_id)[0]->name)->toBe('site_db');
});

it('shows private connection hosts', function () {
    $this->get("/databases/servers/{$this->pg->id}")->assertInertia(fn ($page) => $page
        ->where('connection.driver', 'pgsql')
        ->where('connection.hosts.0.value', '127.0.0.1')
        ->where('connection.hosts.1.value', '10.0.0.20'));

    $this->get("/databases/servers/{$this->mysql->id}")->assertInertia(fn ($page) => $page
        ->where('connection.hosts.0.value', '10.0.0.20'));
});
