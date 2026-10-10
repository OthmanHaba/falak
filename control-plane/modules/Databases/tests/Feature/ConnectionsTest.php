<?php

use Falak\Databases\Contracts\Data\DatabaseConsumer;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Domain\Models\Grant;
use Falak\Identity\Contracts\Role;
use Falak\Network\Contracts\Data\PrivateNetworkMembership;
use Falak\Network\Contracts\PrivateNetwork;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = actingAsMember(Role::Developer);
    $this->server = databases_server($this->organization, attributes: ['name' => 'db-1', 'private_ipv4' => null]);
    $this->web = databases_server($this->organization, attributes: ['name' => 'web-1', 'private_ipv4' => null]);
});

/**
 * Both servers in one WireGuard network: db-1 at 10.90.0.1, web-1 at 10.90.0.2.
 */
function connections_wireguard(object $test): void
{
    $memberships = [
        $test->server->id => [new PrivateNetworkMembership('net1', 'mesh', 'wg-falak0', '10.90.0.1', '10.90.0.0/24', true)],
        $test->web->id => [new PrivateNetworkMembership('net1', 'mesh', 'wg-falak0', '10.90.0.2', '10.90.0.0/24', true)],
    ];

    app()->instance(PrivateNetwork::class, new class($memberships) implements PrivateNetwork
    {
        public function __construct(private array $memberships) {}

        public function addressOf(string $serverId, ?string $networkId = null): ?string
        {
            return ($this->memberships[$serverId][0] ?? null)?->address;
        }

        public function networksOf(string $serverId): array
        {
            return $this->memberships[$serverId] ?? [];
        }
    });
}

it('exposes loopback, host port and database without credentials when no user has access', function () {
    $instance = databases_instance($this->organization, 'mysql', $this->server, ['host_port' => 20007]);
    $database = databases_active_db($instance, 'shop');

    // No consumer: a native one on the server itself.
    expect(app(DatabaseConnections::class)->variables($database->id))->toBe([
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '20007',
        'DB_DATABASE' => 'shop',
        'DATABASE_URL' => 'mysql://127.0.0.1:20007/shop',
    ])->and(app(DatabaseConnections::class)->variables(str_repeat('0', 26)))->toBe([]);
});

it('gives containers of the environment the container name and the engine port', function () {
    [$database, $user, $instance] = databases_service($this->organization, 'postgresql', 'shop', $this->server, ['environment_id' => strtolower((string) Str::ulid())]);
    $consumer = new DatabaseConsumer('Shop', [$this->server->id], true);
    $variables = app(DatabaseConnections::class)->variables($database->id, $consumer);

    expect($variables)->toMatchArray([
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => "falak-db-{$instance->id}",
        'DB_PORT' => '5432',
        'DB_USERNAME' => 'shop',
        'DB_PASSWORD' => $user->password,
        'DATABASE_URL' => "postgresql://shop:{$user->password}@falak-db-{$instance->id}:5432/shop",
    ])->and(app(DatabaseConnections::class)->unreachable($database->id, $consumer))->toBeNull();
});

it('explains that containers need the database in a project environment', function () {
    [$database] = databases_service($this->organization, 'postgresql', 'shop', $this->server);

    expect(app(DatabaseConnections::class)->unreachable($database->id, new DatabaseConsumer('Shop', [$this->server->id], true)))
        ->toContain('is not in a project environment');
});

it('gives native sites on the server loopback and the host port', function () {
    [$database, , $instance] = databases_service($this->organization, 'mariadb', 'shop', $this->server, ['environment_id' => strtolower((string) Str::ulid())]);
    $variables = app(DatabaseConnections::class)->variables($database->id, new DatabaseConsumer('Shop', [$this->server->id], false));

    expect($variables['DB_HOST'])->toBe('127.0.0.1')->and($variables['DB_PORT'])->toBe((string) $instance->host_port)->and($variables['DB_CONNECTION'])->toBe('mariadb');
});

it('never points other servers at a public address', function () {
    [$database] = databases_service($this->organization, 'postgresql', 'shop', $this->server);
    $consumer = new DatabaseConsumer('Shop', [$this->web->id], false);

    expect(app(DatabaseConnections::class)->unreachable($database->id, $consumer))
        ->toBe('Shop runs on web-1, which shares no private network with db-1, and PostgreSQL shop on db-1 is never exposed on a public address for references. Add both servers to a private network (Network → Private networks).')
        ->and(app(DatabaseConnections::class)->variables($database->id, $consumer)['DB_HOST'])->toBe('127.0.0.1');
});

it('reaches other servers over a shared private network once the port is published there', function () {
    connections_wireguard($this);
    [$database, , $instance] = databases_service($this->organization, 'postgresql', 'shop', $this->server);
    $consumer = new DatabaseConsumer('Shop', [$this->web->id], false);

    expect(app(DatabaseConnections::class)->unreachable($database->id, $consumer))->toContain('not published on 10.90.0.1 (private network mesh) yet');

    $instance->forceFill(['published_addresses' => ['10.90.0.1']])->save();
    $variables = app(DatabaseConnections::class)->variables($database->id, $consumer);

    expect(app(DatabaseConnections::class)->unreachable($database->id, $consumer))->toBeNull()
        ->and($variables['DB_HOST'])->toBe('10.90.0.1')
        ->and($variables['DB_PORT'])->toBe((string) $instance->host_port);
});

it('uses the oldest user with all privileges on the database', function () {
    $instance = databases_instance($this->organization, 'postgresql', $this->server);
    $database = databases_active_db($instance, 'shop');
    $reader = databases_active_user($instance, 'reader');
    Grant::query()->create(['user_id' => $reader->id, 'database_id' => $database->id, 'privileges' => ['CONNECT']]);
    $this->travel(1)->seconds();
    $owner = databases_active_user($instance, 'owner', $database);

    expect(app(DatabaseConnections::class)->variables($database->id))->toMatchArray(['DB_USERNAME' => 'owner', 'DB_PASSWORD' => $owner->password]);
});

it('exposes Redis and Valkey with the default user and REDIS_URL', function () {
    [$database, , $instance] = databases_service($this->organization, 'valkey', 'cache', $this->server, ['environment_id' => strtolower((string) Str::ulid())]);
    $variables = app(DatabaseConnections::class)->variables($database->id, new DatabaseConsumer('Shop', [$this->server->id], true));

    expect($variables)->toBe([
        'REDIS_CLIENT' => 'phpredis',
        'REDIS_HOST' => "falak-db-{$instance->id}",
        'REDIS_PORT' => '6379',
        'REDIS_PASSWORD' => $instance->root_password,
        'REDIS_URL' => "redis://default:{$instance->root_password}@falak-db-{$instance->id}:6379",
    ])->and(app(DatabaseConnections::class)->keysFor('valkey'))->toBe(DatabaseConnections::REDIS_KEYS);
});

it('gives Redis a rediss:// URL when the instance requires TLS (it is TLS-only then)', function () {
    [$database, , $instance] = databases_service($this->organization, 'redis', 'cache', $this->server, ['environment_id' => strtolower((string) Str::ulid())]);
    $instance->forceFill(['require_tls' => true])->save();

    $variables = app(DatabaseConnections::class)->variables($database->id, new DatabaseConsumer('Shop', [$this->server->id], true));

    expect($variables['REDIS_URL'])->toBe("rediss://default:{$instance->root_password}@falak-db-{$instance->id}:6379");
});
