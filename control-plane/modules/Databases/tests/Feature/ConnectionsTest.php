<?php

use Falak\Databases\Application\EngineInventory;
use Falak\Databases\Contracts\Data\DatabaseConsumer;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Grant;
use Falak\Identity\Contracts\Role;
use Falak\Servers\Contracts\ServerType;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = actingAsMember(Role::Developer);
});

it('exposes host, port and database without credentials when no user has access', function () {
    $server = databases_server($this->organization, 'mysql', ServerType::Database, ['private_ipv4' => null, 'ipv4' => '203.0.113.9']);
    $engine = app(EngineInventory::class)->sync($server->id);
    $database = databases_active_db($engine, 'shop');

    // No consumer: a native one on the server itself. Never the public address.
    expect(app(DatabaseConnections::class)->variables($database->id))->toBe([
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'shop',
        'DATABASE_URL' => 'mysql://127.0.0.1:3306/shop',
    ])->and(app(DatabaseConnections::class)->variables(str_repeat('0', 26)))->toBe([]);
});

it('leaves a dedicated database server with only a public address unresolved for other servers', function () {
    $server = databases_server($this->organization, 'postgresql', ServerType::Database, ['private_ipv4' => null, 'ipv4' => '203.0.113.9', 'ipv6' => '2001:db8::9']);
    $database = databases_active_db(app(EngineInventory::class)->sync($server->id), 'shop');
    $web = databases_server($this->organization, 'postgresql', ServerType::Web, ['name' => 'web-1']);
    $consumer = new DatabaseConsumer('Shop', [$web->id], false);

    expect(app(DatabaseConnections::class)->unreachable($database->id, $consumer))
        ->toBe("Shop runs on web-1, which shares no private network with {$server->name}, and database references never point at a public address. Add both servers to a private network (Network → Private networks).")
        ->and(app(DatabaseConnections::class)->variables($database->id, $consumer)['DB_HOST'])->toBe('127.0.0.1')
        ->and(app(DatabaseConnections::class)->unreachable($database->id, new DatabaseConsumer('Shop', [$server->id], false)))->toBeNull();
});

it('points at 127.0.0.1 for an engine on an app server, which listens on localhost only', function () {
    $engine = databases_engine($this->organization, 'postgresql', ServerType::App);
    $database = databases_active_db($engine, 'shop');

    $variables = app(DatabaseConnections::class)->variables($database->id);

    expect($variables['DB_HOST'])->toBe('127.0.0.1')
        ->and($variables['DATABASE_URL'])->toBe('postgresql://127.0.0.1:5432/shop');
});

it('uses the oldest user with all privileges on the database', function () {
    $vpc = ['provider' => 'digitalocean', 'provider_credential_id' => '01k6dddddddddddddddddddddd', 'region' => 'fra1'];
    $engine = app(EngineInventory::class)->sync(databases_server($this->organization, 'postgresql', ServerType::Database, $vpc)->id);
    $database = databases_active_db($engine, 'shop');
    $web = databases_server($this->organization, 'postgresql', ServerType::Web, ['private_ipv4' => '10.0.0.21', ...$vpc]);

    foreach ([['reader', ['SELECT']], ['owner', ['ALL PRIVILEGES']], ['late', ['ALL PRIVILEGES']]] as [$username, $privileges]) {
        $user = $engine->users()->create([
            'organization_id' => $this->organization->id,
            'server_id' => $engine->server_id,
            'username' => $username,
            'password' => "{$username}-secret",
            'status' => ResourceStatus::Active,
        ]);
        Grant::query()->create(['user_id' => $user->id, 'database_id' => $database->id, 'privileges' => $privileges]);
    }

    $variables = app(DatabaseConnections::class)->variables($database->id, new DatabaseConsumer('Shop', [$web->id], false));

    expect($variables['DB_USERNAME'])->toBe('owner')
        ->and($variables['DB_PASSWORD'])->toBe('owner-secret')
        ->and($variables['DATABASE_URL'])->toBe('postgresql://owner:owner-secret@10.0.0.20:5432/shop')
        ->and(app(DatabaseDirectory::class)->findMany([$database->id, 'x']))->toHaveKey($database->id)
        ->and(app(DatabaseDirectory::class)->forOrganization($this->organization->id))->toHaveCount(1);
});
