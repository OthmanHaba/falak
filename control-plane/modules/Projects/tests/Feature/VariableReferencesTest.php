<?php

use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Domain\Models\DatabaseInstance;
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

/**
 * A database container of the test's environment on its own (database) server, published on that server's private
 * address (a site of the environment runs elsewhere).
 */
function refs_instance(object $test, string $engine = 'postgresql', array $attributes = []): DatabaseInstance
{
    $server = databases_server($test->organization, ServerType::Database, $attributes);

    return databases_instance($test->organization, $engine, $server, ['environment_id' => $test->environment->id, 'published_addresses' => array_values(array_filter([$server->private_ipv4]))]);
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
    [$database, $user, $engine] = projects_database($this->organization, 'shop', $this->environment, instance: refs_instance($this, attributes: refs_do_fra()));
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
            'DATABASE_URL' => "postgresql://shop_user:p%40ss%2Fword@{$engineServer->private_ipv4}:{$engine->host_port}/shop",
            'DB_HOST' => $engineServer->private_ipv4,
            'DB_PORT' => (string) $engine->host_port,
            'DB_DATABASE' => 'shop',
            'DB_USERNAME' => 'shop_user',
            'DB_PASSWORD' => 'p@ss/word',
            'API_URL' => 'https://api.test/v1?key=secret-key',
            'PLAIN' => 'no references',
        ])
        ->and($result->references)->toContain(['service' => 'shop', 'key' => 'DATABASE_URL'], ['service' => 'api', 'key' => 'PUBLIC_URL']);
});

it('prefers the private network address of the database server', function () {
    [, , $engine] = projects_database($this->organization, 'shop', $this->environment, 'mysql', refs_instance($this, 'mysql', refs_do_fra()));
    $webServer = Server::factory()->create(['organization_id' => $this->organization->id, 'private_ipv4' => '10.0.0.30', ...refs_do_fra()]);
    refs_wireguard($this, ['10.90.0.7' => Server::query()->find($engine->server_id), '10.90.0.8' => $webServer]);
    $engine->forceFill(['published_addresses' => ['10.90.0.7']])->save();
    $web = projects_site($this->organization, 'Web', [], $this->environment, [$webServer]);

    $result = app(VariableReferences::class)->resolve($this->environment->id, $web->id, ['URL' => '${{ shop.DATABASE_URL }}', 'CONN' => '${{ shop.DB_CONNECTION }}']);

    expect($result->variables)->toBe(['URL' => "mysql://shop_user:p%40ss%2Fword@10.90.0.7:{$engine->host_port}/shop", 'CONN' => 'mysql']);
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

it('gives native sites on the container\'s server 127.0.0.1 and its host port, and other servers a reason', function () {
    [, , $instance] = projects_database($this->organization, 'shop', $this->environment);
    $engineServer = Server::query()->find($instance->server_id);
    $other = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-2']);
    $refs = ['URL' => '${{ shop.DATABASE_URL }}', 'HOST' => '${{ shop.DB_HOST }}', 'PORT' => '${{ shop.DB_PORT }}', 'NAME' => '${{ shop.DB_DATABASE }}'];

    $local = projects_site($this->organization, 'Local', [], $this->environment, [$engineServer]);
    $result = $this->references->resolve($this->environment->id, $local->id, $refs);
    expect($result->errors)->toBe([])
        ->and($result->variables['HOST'])->toBe('127.0.0.1')
        ->and($result->variables['PORT'])->toBe((string) $instance->host_port)
        ->and($result->variables['URL'])->toStartWith("postgresql://shop_user:p%40ss%2Fword@127.0.0.1:{$instance->host_port}/");

    // Another server without a shared private network: a clear error; keys without the host still resolve.
    $spread = projects_site($this->organization, 'Spread', [], $this->environment, [$engineServer, $other]);
    $result = $this->references->resolve($this->environment->id, $spread->id, $refs);
    expect($result->errors[0])->toStartWith("URL: shop.DATABASE_URL cannot be used here: Spread runs on web-2, which shares no private network with {$engineServer->name}")
        ->and($result->errors)->toHaveCount(2)
        ->and($result->variables['NAME'])->toBe('shop');
});

it('gives containers of the environment on the container\'s server its name on the environment network and the engine port', function () {
    [, , $instance] = projects_database($this->organization, 'shop', $this->environment);
    $engineServer = Server::query()->find($instance->server_id);
    $refs = ['URL' => '${{ shop.DATABASE_URL }}', 'HOST' => '${{ shop.DB_HOST }}', 'PORT' => '${{ shop.DB_PORT }}'];

    foreach (['docker', 'compose'] as $runtime) {
        $site = projects_site($this->organization, "Box {$runtime}", [], $this->environment, [$engineServer], ['runtime' => $runtime, 'framework' => 'docker', 'php_version' => null]);
        $result = $this->references->resolve($this->environment->id, $site->id, $refs);

        expect($result->errors)->toBe([])
            ->and($result->variables['HOST'])->toBe("falak-db-{$instance->id}")
            ->and($result->variables['PORT'])->toBe('5432')
            ->and($result->variables['URL'])->toContain("@falak-db-{$instance->id}:5432/");
    }

    // Native sites on that server keep the loopback address.
    $local = projects_site($this->organization, 'Local', [], $this->environment, [$engineServer]);
    expect($this->references->resolve($this->environment->id, $local->id, $refs)->variables['HOST'])->toBe('127.0.0.1');
});

it('leaves containers unresolved when the database container is on no environment network', function () {
    [, , $instance] = projects_database($this->organization, 'shop', $this->environment);
    $instance->forceFill(['environment_id' => null])->save();
    $box = projects_site($this->organization, 'Box', [], $this->environment, [Server::query()->find($instance->server_id)], ['runtime' => 'docker', 'framework' => 'docker', 'php_version' => null]);

    expect($this->references->resolve($this->environment->id, $box->id, ['HOST' => '${{ shop.DB_HOST }}'])->errors[0])
        ->toContain('Box runs in a container, but PostgreSQL')
        ->toContain('is not in a project environment');
});

it('gives other servers the container server\'s private address and host port once it is published there', function () {
    [, , $instance] = projects_database($this->organization, 'shop', $this->environment, instance: refs_instance($this, attributes: refs_do_fra()));
    $engineServer = Server::query()->find($instance->server_id);
    $other = Server::factory()->create(['organization_id' => $this->organization->id, 'private_ipv4' => '10.0.0.31', ...refs_do_fra()]);
    $box = projects_site($this->organization, 'Box', [], $this->environment, [$other], ['runtime' => 'docker', 'framework' => 'docker', 'php_version' => null]);
    $refs = ['HOST' => '${{ shop.DB_HOST }}', 'PORT' => '${{ shop.DB_PORT }}'];

    // Not published yet: Falak is applying it.
    $instance->forceFill(['published_addresses' => null])->save();
    expect($this->references->resolve($this->environment->id, $box->id, $refs)->errors[0])->toContain("is not published on {$engineServer->private_ipv4}");

    $instance->forceFill(['published_addresses' => [$engineServer->private_ipv4]])->save();
    $result = $this->references->resolve($this->environment->id, $box->id, $refs);

    expect($result->errors)->toBe([])
        ->and($result->variables)->toBe(['HOST' => $engineServer->private_ipv4, 'PORT' => (string) $instance->host_port]);
});

it('never points a database container\'s references at a public address', function () {
    // Public address only, and a private IPv4 on a provider whose private networks are opt-in: neither proves a
    // shared network, so the reference stays unresolved instead of sending the password there.
    [, , $instance] = projects_database($this->organization, 'shop', $this->environment, instance: refs_instance($this, attributes: ['provider' => 'hetzner', 'private_ipv4' => null, 'ipv4' => '203.0.113.9']));
    $engineName = Server::query()->find($instance->server_id)->name;
    $web = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-9', 'provider' => 'hetzner', 'private_ipv4' => '10.0.0.30']);
    $site = projects_site($this->organization, 'Shop', [], $this->environment, [$web]);
    $refs = ['URL' => '${{ shop.DATABASE_URL }}', 'NAME' => '${{ shop.DB_DATABASE }}'];

    $result = $this->references->resolve($this->environment->id, $site->id, $refs);

    expect($result->errors)->toBe(["URL: shop.DATABASE_URL cannot be used here: Shop runs on web-9, which shares no private network with {$engineName}, and PostgreSQL {$instance->name} on {$engineName} is never exposed on a public address for references. Add both servers to a private network (Network → Private networks)."])
        ->and($result->variables['NAME'])->toBe('shop');

    // Hetzner private IPs on both sides prove nothing either.
    Server::query()->whereKey($instance->server_id)->update(['private_ipv4' => '10.0.0.20']);
    expect($this->references->resolve($this->environment->id, $site->id, $refs)->errors)->toHaveCount(1);

    // A Falak private network both are in, with the port published there: resolved.
    refs_wireguard($this, ['10.90.0.2' => Server::query()->find($instance->server_id), '10.90.0.3' => $web]);
    $instance->forceFill(['published_addresses' => ['10.90.0.2']])->save();
    $result = $this->references->resolve($this->environment->id, $site->id, $refs);
    expect($result->errors)->toBe([])
        ->and($result->variables['URL'])->toBe("postgresql://shop_user:p%40ss%2Fword@10.90.0.2:{$instance->host_port}/shop");
});
