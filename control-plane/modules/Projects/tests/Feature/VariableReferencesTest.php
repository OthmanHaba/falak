<?php

use Falak\Databases\Application\EngineInventory;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

/** DigitalOcean droplets Falak created with one credential in one region share the region's default VPC. */
function refs_do_fra(): array
{
    return ['provider' => 'digitalocean', 'provider_credential_id' => '01k6bbbbbbbbbbbbbbbbbbbbbb', 'region' => 'fra1'];
}

/** A dedicated database server's engine. */
function refs_dedicated_engine(object $test, string $engine = 'postgresql', array $attributes = []): DatabaseServer
{
    return app(EngineInventory::class)->sync(databases_server($test->organization, $engine, ServerType::Database, $attributes)->id);
}

/** @param  array<string, Server>  $members  WireGuard address => server */
function refs_wireguard(object $test, array $members): void
{
    $network = PrivateNetwork::query()->create(['organization_id' => $test->organization->id, 'name' => 'mesh', 'cidr' => '10.90.0.0/24', 'interface' => 'wg-'.Str::lower(Str::random(8)), 'listen_port' => 51820]);

    foreach ($members as $address => $server) {
        PrivateNetworkMember::query()->create(['organization_id' => $test->organization->id, 'network_id' => $network->id, 'server_id' => $server->id, 'address' => $address, 'public_key' => base64_encode(random_bytes(32)), 'private_key' => base64_encode(random_bytes(32)), 'key_status' => 'installed', 'status' => ApplyStatus::Applied]);
    }
}

beforeEach(function () {
    [, $this->organization] = memberOf();
    $this->environment = projects_default_env($this->organization);
    $this->references = app(VariableReferences::class);
});

it('resolves database and site references in the same environment', function () {
    [$database, $user, $engine] = projects_database($this->organization, 'shop', $this->environment, engineServer: refs_dedicated_engine($this, attributes: refs_do_fra()));
    $engineServer = Server::query()->find($engine->server_id);
    projects_site($this->organization, 'Api', ['API_KEY' => 'secret-key', 'PUBLIC_URL' => 'https://api.test'], $this->environment);
    $webServer = Server::factory()->create(['organization_id' => $this->organization->id, 'private_ipv4' => '10.0.0.30', ...refs_do_fra()]);
    $web = projects_site($this->organization, 'Web', [], $this->environment, [$webServer]);

    $result = $this->references->resolve($this->environment->id, $web->id, [
        'DATABASE_URL' => '${{ shop.DATABASE_URL }}',
        'DB_HOST' => '${{shop.DB_HOST}}',
        'DB_PORT' => '${{ shop.DB_PORT }}',
        'DB_DATABASE' => '${{ Shop.DB_DATABASE }}',
        'DB_USERNAME' => '${{ shop.DB_USERNAME }}',
        'DB_PASSWORD' => '${{ shop.DB_PASSWORD }}',
        'API_URL' => '${{ api.PUBLIC_URL }}/v1?key=${{ API.API_KEY }}',
        'PLAIN' => 'no references',
    ]);

    expect($result->ok())->toBeTrue()
        ->and($result->errors)->toBe([])
        ->and($result->variables)->toBe([
            'DATABASE_URL' => "postgresql://shop_user:p%40ss%2Fword@{$engineServer->private_ipv4}:5432/shop",
            'DB_HOST' => $engineServer->private_ipv4,
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'shop',
            'DB_USERNAME' => 'shop_user',
            'DB_PASSWORD' => 'p@ss/word',
            'API_URL' => 'https://api.test/v1?key=secret-key',
            'PLAIN' => 'no references',
        ])
        ->and($result->references)->toContain(['service' => 'shop', 'key' => 'DATABASE_URL'], ['service' => 'api', 'key' => 'PUBLIC_URL']);
});

it('prefers the private network address of the database server', function () {
    [, , $engine] = projects_database($this->organization, 'shop', $this->environment, 'mysql', refs_dedicated_engine($this, 'mysql', refs_do_fra()));
    $webServer = Server::factory()->create(['organization_id' => $this->organization->id, 'private_ipv4' => '10.0.0.30', ...refs_do_fra()]);
    refs_wireguard($this, ['10.90.0.7' => Server::query()->find($engine->server_id), '10.90.0.8' => $webServer]);
    $web = projects_site($this->organization, 'Web', [], $this->environment, [$webServer]);

    $result = app(VariableReferences::class)->resolve($this->environment->id, $web->id, ['URL' => '${{ shop.DATABASE_URL }}', 'CONN' => '${{ shop.DB_CONNECTION }}']);

    expect($result->variables)->toBe(['URL' => 'mysql://shop_user:p%40ss%2Fword@10.90.0.7:3306/shop', 'CONN' => 'mysql']);
});

it('resolves chained site references and references to the site itself', function () {
    projects_database($this->organization, 'shop', $this->environment);
    projects_site($this->organization, 'Api', ['DB' => '${{ shop.DB_DATABASE }}'], $this->environment);
    $web = projects_site($this->organization, 'Web', [], $this->environment);

    $result = $this->references->resolveForSite($web->id, ['FROM_API' => '${{ api.DB }}', 'HOST' => 'web.test', 'URL' => 'https://${{ web.HOST }}']);

    expect($result->errors)->toBe([])
        ->and($result->variables)->toBe(['FROM_API' => 'shop', 'HOST' => 'web.test', 'URL' => 'https://web.test']);
});

it('reports unknown services and keys', function () {
    projects_database($this->organization, 'shop', $this->environment);
    $web = projects_site($this->organization, 'Web', [], $this->environment);

    $result = $this->references->resolve($this->environment->id, $web->id, [
        'A' => '${{ nope.KEY }}',
        'B' => '${{ shop.REDIS_URL }}',
        'C' => 'ok',
    ]);

    expect($result->ok())->toBeFalse()
        ->and($result->variables)->toBe(['A' => '${{ nope.KEY }}', 'B' => '${{ shop.REDIS_URL }}', 'C' => 'ok'])
        ->and($result->errors[0])->toBe('A: unknown service "nope" in ${{ nope.KEY }}.')
        ->and($result->errors[1])->toStartWith('B: service "shop" has no variable REDIS_URL (it exposes DB_CONNECTION, DB_HOST')
        ->and($result->errorSummary())->toStartWith('Unresolved variable references: A: unknown service');
});

it('detects reference cycles', function () {
    projects_site($this->organization, 'Api', ['X' => '${{ web.Y }}'], $this->environment);
    $web = projects_site($this->organization, 'Web', [], $this->environment);

    $cycle = $this->references->resolve($this->environment->id, $web->id, ['Y' => '${{ api.X }}']);
    $self = $this->references->resolve($this->environment->id, $web->id, ['Z' => 'a${{ web.Z }}']);

    expect($cycle->errors)->toBe(['Y: reference cycle Web.Y → Api.X → Web.Y.'])
        ->and($self->errors)->toBe(['Z: reference cycle Web.Z → Web.Z.']);
});

it('never resolves services of another environment', function () {
    $staging = projects_environment($this->organization, 'staging');
    projects_database($this->organization, 'shop', $this->environment);
    $stagingWeb = projects_site($this->organization, 'Web staging', [], $staging);

    $result = $this->references->resolveForSite($stagingWeb->id, ['DB' => '${{ shop.DB_HOST }}']);

    expect($result->errors)->toBe(['DB: unknown service "shop" in ${{ shop.DB_HOST }}.']);
});

it('passes variables through unchanged when there are no references, even for unplaced sites', function () {
    $site = projects_site($this->organization, 'Loose');

    expect($this->references->resolveForSite($site->id, ['A' => 'b'])->variables)->toBe(['A' => 'b'])
        ->and($this->references->resolveForSite($site->id, ['A' => '${{ db.X }}'])->errors)
        ->toBe(['The site is not part of a project environment, so ${{ service.KEY }} references cannot be resolved.']);
});

it('lists references without resolving them', function () {
    expect($this->references->referencesIn(['A' => '${{ my.api.KEY }} and ${{ db.DB_HOST}}', 'B' => '$KEY {{ x.Y }}']))->toBe([
        ['service' => 'my.api', 'key' => 'KEY', 'variable' => 'A'],
        ['service' => 'db', 'key' => 'DB_HOST', 'variable' => 'A'],
    ]);
});

it('lists the services of an environment with the keys a reference can use, never values', function () {
    [$member] = memberOf($this->organization);
    projects_database($this->organization, 'shop', $this->environment);
    projects_site($this->organization, 'Shop API', ['API_KEY' => 'secret-key'], $this->environment);
    $project = $this->environment->project;

    $response = $this->actingAs($member)->getJson("/projects/{$project->id}/{$this->environment->slug}/variables")->assertOk();

    $services = collect($response->json('data.services'))->keyBy('name');
    expect($services['shop']['kind'])->toBe('database')
        ->and($services['shop']['keys'])->toBe(DatabaseConnections::KEYS)
        ->and($services['Shop API']['handle'])->toBe('shop-api')
        ->and($services['Shop API']['keys'])->toBe(['API_KEY'])
        ->and($response->getContent())->not->toContain('secret-key');

    [$stranger] = memberOf();
    $this->actingAs($stranger)->getJson("/projects/{$project->id}/{$this->environment->slug}/variables")->assertNotFound();
});

it('gives 127.0.0.1 for an app-server engine only to native sites running on that server alone', function () {
    [, , $engine] = projects_database($this->organization, 'shop', $this->environment);
    $engineServer = Server::query()->find($engine->server_id);
    $other = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-2']);
    $refs = ['URL' => '${{ shop.DATABASE_URL }}', 'HOST' => '${{ shop.DB_HOST }}', 'NAME' => '${{ shop.DB_DATABASE }}'];

    $local = projects_site($this->organization, 'Local', [], $this->environment, [$engineServer]);
    $result = $this->references->resolve($this->environment->id, $local->id, $refs);
    expect($result->errors)->toBe([])
        ->and($result->variables['HOST'])->toBe('127.0.0.1')
        ->and($result->variables['URL'])->toStartWith('postgresql://shop_user:p%40ss%2Fword@127.0.0.1:5432/');

    // Another server: a clear error instead of a host that cannot connect; keys without the host still resolve.
    $spread = projects_site($this->organization, 'Spread', [], $this->environment, [$engineServer, $other]);
    $result = $this->references->resolve($this->environment->id, $spread->id, $refs);
    $reason = "Spread runs on web-2, but the database runs on {$engineServer->name}, which accepts connections from that server only (move it to a dedicated database server to reach it from elsewhere).";
    expect($result->errors)->toBe(["URL: shop.DATABASE_URL cannot be used here: {$reason}", "HOST: shop.DB_HOST cannot be used here: {$reason}"])
        ->and($result->variables['NAME'])->toBe('shop');

    // A container on the same server: 127.0.0.1 would be the container itself. Until the agent supports container
    // access, a clear error.
    $docker = projects_site($this->organization, 'Box', [], $this->environment, [$engineServer], ['runtime' => 'docker', 'framework' => 'docker', 'php_version' => null]);
    expect($this->references->resolve($this->environment->id, $docker->id, ['HOST' => '${{ shop.DB_HOST }}'])->errors[0])
        ->toStartWith('HOST: shop.DB_HOST cannot be used here: Box runs in a container, but containers on');
});

it('gives containers on the engine server the Docker bridge address once container access is on', function () {
    [, , $engine] = projects_database($this->organization, 'shop', $this->environment);
    $engine->forceFill(['container_access' => true])->save();
    $engineServer = Server::query()->find($engine->server_id);
    $refs = ['URL' => '${{ shop.DATABASE_URL }}', 'HOST' => '${{ shop.DB_HOST }}'];

    foreach (['docker', 'compose', 'function'] as $runtime) {
        $site = projects_site($this->organization, "Box {$runtime}", [], $this->environment, [$engineServer], ['runtime' => $runtime, 'framework' => 'docker', 'php_version' => null]);
        $result = $this->references->resolve($this->environment->id, $site->id, $refs);

        expect($result->errors)->toBe([])
            ->and($result->variables['HOST'])->toBe('172.17.0.1')
            ->and($result->variables['URL'])->toContain('@172.17.0.1:5432/');
    }

    // Native sites on that server keep the loopback address.
    $local = projects_site($this->organization, 'Local', [], $this->environment, [$engineServer]);
    expect($this->references->resolve($this->environment->id, $local->id, $refs)->variables['HOST'])->toBe('127.0.0.1');
});

it('uses the docker0 address the agent reported on the server, else databases.docker_bridge_host', function () {
    [, , $engine] = projects_database($this->organization, 'shop', $this->environment);
    $engine->forceFill(['container_access' => true])->save();
    $engineServer = Server::query()->find($engine->server_id);
    $box = projects_site($this->organization, 'Box', [], $this->environment, [$engineServer], ['runtime' => 'docker', 'framework' => 'docker', 'php_version' => null]);

    config(['databases.docker_bridge_host' => '172.31.0.1']);
    expect($this->references->resolve($this->environment->id, $box->id, ['HOST' => '${{ shop.DB_HOST }}'])->variables['HOST'])->toBe('172.31.0.1');

    // A Redis instance on the server listens on docker0: its address is the real one.
    $engineServer->forceFill(['stack' => ['database' => 'postgresql', 'cache' => 'redis']])->save();
    $cache = app(EngineInventory::class)->sync($engineServer->id, Engine::Redis);
    $cache->databases()->create(['organization_id' => $cache->organization_id, 'server_id' => $cache->server_id, 'name' => 'cache', 'status' => 'active', 'port' => 6380, 'network' => ['bind' => ['127.0.0.1', '172.20.0.1'], 'container_host' => '172.20.0.1']]);

    expect(app(VariableReferences::class)->resolve($this->environment->id, $box->id, ['HOST' => '${{ shop.DB_HOST }}'])->variables['HOST'])->toBe('172.20.0.1');
});

it('leaves containers unresolved when container access to databases is turned off', function () {
    [, , $engine] = projects_database($this->organization, 'shop', $this->environment);
    $engine->forceFill(['container_access' => true])->save();
    config(['databases.container_networks' => []]);
    $box = projects_site($this->organization, 'Box', [], $this->environment, [Server::query()->find($engine->server_id)], ['runtime' => 'docker', 'framework' => 'docker', 'php_version' => null]);

    expect($this->references->resolve($this->environment->id, $box->id, ['HOST' => '${{ shop.DB_HOST }}'])->errors)
        ->toBe(['HOST: shop.DB_HOST cannot be used here: Box runs in a container, but container access to databases is turned off (FALAK_DOCKER_NETWORKS).']);
});

it('gives containers and other servers the address of a dedicated database server on a private network they share', function () {
    [, , $engine] = projects_database($this->organization, 'shop', $this->environment, engineServer: refs_dedicated_engine($this, attributes: refs_do_fra()));
    $engineServer = Server::query()->find($engine->server_id);
    $other = Server::factory()->create(['organization_id' => $this->organization->id, 'private_ipv4' => '10.0.0.31', ...refs_do_fra()]);
    $fn = projects_site($this->organization, 'Fn', [], $this->environment, [$other], ['runtime' => 'function', 'framework' => 'docker', 'php_version' => null]);

    $result = $this->references->resolve($this->environment->id, $fn->id, ['HOST' => '${{ shop.DB_HOST }}']);

    expect($result->errors)->toBe([])
        ->and($result->variables['HOST'])->toBe($engineServer->private_ipv4);

    // Containers on the database server itself: the Docker bridge.
    $box = projects_site($this->organization, 'Box', [], $this->environment, [$engineServer], ['runtime' => 'docker', 'framework' => 'docker', 'php_version' => null]);
    expect($this->references->resolve($this->environment->id, $box->id, ['HOST' => '${{ shop.DB_HOST }}'])->variables['HOST'])->toBe('172.17.0.1');
});

it('never points a dedicated database server\'s references at a public address', function () {
    // Public address only, and a private IPv4 on a provider whose private networks are opt-in: neither proves a
    // shared network, so the reference stays unresolved instead of sending the password there.
    [, , $engine] = projects_database($this->organization, 'shop', $this->environment, engineServer: refs_dedicated_engine($this, attributes: ['provider' => 'hetzner', 'private_ipv4' => null, 'ipv4' => '203.0.113.9']));
    $engineName = Server::query()->find($engine->server_id)->name;
    $web = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-9', 'provider' => 'hetzner', 'private_ipv4' => '10.0.0.30']);
    $site = projects_site($this->organization, 'Shop', [], $this->environment, [$web]);
    $refs = ['URL' => '${{ shop.DATABASE_URL }}', 'NAME' => '${{ shop.DB_DATABASE }}'];

    $result = $this->references->resolve($this->environment->id, $site->id, $refs);

    expect($result->errors)->toBe(["URL: shop.DATABASE_URL cannot be used here: Shop runs on web-9, which shares no private network with {$engineName}, and database references never point at a public address. Add both servers to a private network (Network → Private networks)."])
        ->and($result->variables['NAME'])->toBe('shop');

    // Hetzner private IPs on both sides prove nothing either.
    Server::query()->whereKey($engine->server_id)->update(['private_ipv4' => '10.0.0.20']);
    expect($this->references->resolve($this->environment->id, $site->id, $refs)->errors)->toHaveCount(1);

    // A Falak private network both are in: resolved there.
    refs_wireguard($this, ['10.90.0.2' => Server::query()->find($engine->server_id), '10.90.0.3' => $web]);
    $result = $this->references->resolve($this->environment->id, $site->id, $refs);
    expect($result->errors)->toBe([])
        ->and($result->variables['URL'])->toBe('postgresql://shop_user:p%40ss%2Fword@10.90.0.2:5432/shop');
});
