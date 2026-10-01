<?php

use Kiln\Databases\Application\EngineInventory;
use Kiln\Databases\Contracts\DatabaseConnections;
use Kiln\Databases\Contracts\DatabaseDirectory;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Grant;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Contracts\ServerType;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = actingAsMember(Role::Developer);
});

it('exposes host, port and database without credentials when no user has access', function () {
    $server = databases_server($this->organization, 'mysql', ServerType::Database, ['private_ipv4' => null, 'ipv4' => '203.0.113.9']);
    $engine = app(EngineInventory::class)->sync($server->id);
    $database = databases_active_db($engine, 'shop');

    expect(app(DatabaseConnections::class)->variables($database->id))->toBe([
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '203.0.113.9',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'shop',
        'DATABASE_URL' => 'mysql://203.0.113.9:3306/shop',
    ])->and(app(DatabaseConnections::class)->variables(str_repeat('0', 26)))->toBe([]);
});

it('points at 127.0.0.1 for an engine on an app server, which listens on localhost only', function () {
    $engine = databases_engine($this->organization, 'postgresql', ServerType::App);
    $database = databases_active_db($engine, 'shop');

    $variables = app(DatabaseConnections::class)->variables($database->id);

    expect($variables['DB_HOST'])->toBe('127.0.0.1')
        ->and($variables['DATABASE_URL'])->toBe('postgresql://127.0.0.1:5432/shop');
});

it('uses the oldest user with all privileges on the database', function () {
    $engine = databases_engine($this->organization, 'postgresql', ServerType::Database);
    $database = databases_active_db($engine, 'shop');

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

    $variables = app(DatabaseConnections::class)->variables($database->id);

    expect($variables['DB_USERNAME'])->toBe('owner')
        ->and($variables['DB_PASSWORD'])->toBe('owner-secret')
        ->and($variables['DATABASE_URL'])->toBe('postgresql://owner:owner-secret@10.0.0.20:5432/shop')
        ->and(app(DatabaseDirectory::class)->findMany([$database->id, 'x']))->toHaveKey($database->id)
        ->and(app(DatabaseDirectory::class)->forOrganization($this->organization->id))->toHaveCount(1);
});
