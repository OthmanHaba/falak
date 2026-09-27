<?php

use Illuminate\Support\Facades\Http;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Domain\Models\ServerState;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Domain\Models\Site;
use Kiln\SourceControl\Contracts\ProviderType;
use Kiln\SourceControl\Contracts\SourceControlGateway;
use Kiln\SourceControl\Domain\Models\Connection;

/*
 * Real Sites + Edge (+ SourceControl) wiring; only the agent is faked (every payload schema-validated).
 */

require_once __DIR__.'/../../../Sites/tests/Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    config(['sites.test_domain' => 'kiln.test', 'edge.acme_email' => 'ops@example.com']);
});

function integration_compile_valid(string $serverId): array
{
    $payload = app(EdgeRoutes::class)->compile($serverId);
    $errors = app(ProtocolSchemas::class)->validateCommand('edge.caddy.apply', ProtocolSchemas::toJson($payload));

    expect($errors)->toBe([]);

    return $payload;
}

it('routes a php-fpm site on two servers and applies the edge on creation', function () {
    sites_fake_source_control();
    $a = sites_server($this->organization->id, ['name' => 'web-1'], ['8.4'], 'fpm');
    $b = sites_server($this->organization->id, ['name' => 'web-2'], ['8.4'], 'fpm');

    $this->post('/sites', sites_input([$a->id, $b->id], ['runtime' => 'php-fpm']))->assertSessionHasNoErrors();
    $site = Site::query()->firstOrFail();

    $applies = $this->agents->ofType('edge.caddy.apply');
    expect(collect($applies)->pluck('server_id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());

    $this->post("/sites/{$site->id}/domains", ['name' => 'Shop.Example.com', 'www_redirect' => 'to_www'])->assertSessionHasNoErrors();

    foreach ([$a, $b] as $server) {
        $payload = integration_compile_valid($server->id);
        $routeId = app(EdgeRoutes::class)->routeId($site->id);
        $entry = collect($payload['sites'])->firstWhere('id', $routeId);

        expect($payload['acme_email'])->toBe('ops@example.com')
            ->and($entry['kind'])->toBe('php_fpm')
            ->and($entry['root'])->toBe('/srv/kiln/sites/shop/current/public')
            ->and($entry['php_fpm_socket'])->toBe('/run/php/kiln-shop-8.4.sock')
            ->and($entry['domains'])->toContain('www.shop.example.com')->toContain('shop.kiln.test')
            ->and($entry['redirect_domains'])->toBe(['shop.example.com']);
    }

    // The latest dispatched apply for each server carries the domain.
    expect($this->agents->last('edge.caddy.apply')['payload']['sites'][0]['domains'])->toContain('www.shop.example.com');

    // The primary domain shows up in the Sites header.
    $this->getJson("/sites/{$site->id}/settings")->assertJsonPath('data.site.primary_domain', 'www.shop.example.com');
});

it('re-applies after a PHP version change and not for unrelated changes', function () {
    sites_fake_source_control();
    $server = sites_server($this->organization->id, [], ['8.3', '8.4'], 'fpm');
    $this->post('/sites', sites_input([$server->id], ['runtime' => 'php-fpm']));
    $site = Site::query()->firstOrFail();
    $before = count($this->agents->ofType('edge.caddy.apply'));

    $this->put("/sites/{$site->id}/deploy-script", ['script' => 'echo hi']);
    expect($this->agents->ofType('edge.caddy.apply'))->toHaveCount($before);

    $this->patch("/sites/{$site->id}", ['php_version' => '8.3'])->assertSessionHasNoErrors();

    $apply = $this->agents->last('edge.caddy.apply');
    expect($this->agents->ofType('edge.caddy.apply'))->toHaveCount($before + 1)
        ->and($apply['payload']['sites'][0]['php_fpm_socket'])->toBe('/run/php/kiln-shop-8.3.sock');
});

it('proxies node sites to their port and container sites to the recorded upstream', function () {
    sites_fake_source_control();
    $server = sites_server($this->organization->id, [], ['8.4'], 'frankenphp', docker: true);

    $this->post('/sites', sites_input([$server->id], ['name' => 'Next', 'framework' => 'next', 'runtime' => 'node', 'php_version' => null]))->assertSessionHasNoErrors();
    $this->post('/sites', sites_input([$server->id], ['name' => 'Api', 'framework' => 'docker', 'runtime' => 'docker', 'php_version' => null]))->assertSessionHasNoErrors();

    $next = Site::query()->where('name', 'Next')->firstOrFail();
    $api = Site::query()->where('name', 'Api')->firstOrFail();
    $routes = app(EdgeRoutes::class);

    $payload = integration_compile_valid($server->id);
    $entry = fn (array $payload, Site $site) => collect($payload['sites'])->firstWhere('id', $routes->routeId($site->id));

    expect($entry($payload, $next))->toMatchArray(['kind' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:3000']], 'health_uri' => '/'])
        ->and($entry($payload, $api)['upstreams'])->toBe([['dial' => '127.0.0.1:3001']]);

    // deploy.container.swap reports the new upstream; later configs keep it.
    $routes->recordUpstream($api->id, $server->id, '127.0.0.1:18081');

    $payload = integration_compile_valid($server->id);
    expect($entry($payload, $api)['upstreams'])->toBe([['dial' => '127.0.0.1:18081']])
        ->and($this->agents->last('edge.caddy.apply')['payload'])->toEqual($payload);

    // A container swap payload built by Deployments with the route id validates against its schema.
    $swap = ['site' => $api->slug, 'image' => 'registry.local/api:1', 'container_port' => 8080, 'ports' => ['blue' => 18080, 'green' => 18081], 'edge_route_id' => $routes->routeId($api->id)];
    expect(app(ProtocolSchemas::class)->validateCommand('deploy.container.swap', ProtocolSchemas::toJson($swap)))->toBe([]);
});

it('load-balances a multi-server site through an lb server', function () {
    sites_fake_source_control();
    $a = sites_server($this->organization->id, ['name' => 'web-1', 'private_ipv4' => '10.0.0.11']);
    $b = sites_server($this->organization->id, ['name' => 'web-2', 'private_ipv4' => '10.0.0.12']);
    $lb = sites_server($this->organization->id, ['name' => 'lb-1', 'type' => ServerType::LoadBalancer], []);

    $this->post('/sites', sites_input([$a->id, $b->id]));
    $site = Site::query()->firstOrFail();
    $this->post("/sites/{$site->id}/domains", ['name' => 'shop.example.com']);

    $this->put("/sites/{$site->id}/load-balancer", [
        'server_id' => $lb->id,
        'policy' => 'least_conn',
        'health_uri' => '/up',
        'weights' => [$a->id => 2, $b->id => 1],
    ])->assertSessionHasNoErrors();

    $front = collect(integration_compile_valid($lb->id)['sites'])->first();
    expect($front['kind'])->toBe('reverse_proxy')
        ->and($front['lb_policy'])->toBe('least_conn')
        ->and($front['upstreams'])->toBe([['dial' => '10.0.0.11:80'], ['dial' => '10.0.0.11:80'], ['dial' => '10.0.0.12:80']])
        ->and($front['tls'])->toBe(['mode' => 'acme']);

    $backend = collect(integration_compile_valid($a->id)['sites'])->first();
    expect($backend['kind'])->toBe('frankenphp')->and($backend['tls'])->toBe(['mode' => 'off']);

    expect(collect($this->agents->ofType('edge.caddy.apply'))->pluck('server_id')->unique()->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id, $lb->id])->sort()->values()->all());

    // Removing a target drops it from the LB rotation.
    $this->put("/sites/{$site->id}/targets", ['server_ids' => [$b->id], 'leader_server_id' => $b->id])->assertSessionHasNoErrors();
    expect(collect(integration_compile_valid($lb->id)['sites'])->first()['upstreams'])->toBe([['dial' => '10.0.0.12:80']])
        ->and(integration_compile_valid($a->id)['sites'])->toBe([]);
});

it('removes routes when the site is deleted', function () {
    sites_fake_source_control();
    $server = sites_server($this->organization->id);
    $this->post('/sites', sites_input([$server->id]));
    $site = Site::query()->firstOrFail();
    $this->post("/sites/{$site->id}/domains", ['name' => 'shop.example.com']);

    [$admin] = memberOf($this->organization, Role::Admin);
    $this->actingAs($admin)->delete("/sites/{$site->id}", ['name' => 'Shop'])->assertRedirect();

    expect($this->agents->last('edge.caddy.apply')['payload']['sites'])->toBe([])
        ->and(ServerState::query()->find($server->id))->not->toBeNull();
});

it('creates a site from a real GitHub connection: deploy key and webhook through the API, push lookups', function () {
    Http::fake([
        'api.github.com/repos/acme/shop/keys' => Http::response(['id' => 42], 201),
        'api.github.com/repos/acme/shop/hooks' => Http::response(['id' => 7], 201),
        '*' => Http::response([], 200),
    ]);

    $connection = new Connection(['organization_id' => $this->organization->id, 'provider' => ProviderType::GitHub, 'name' => 'GitHub', 'auth_type' => 'token', 'account' => 'acme']);
    $connection->credentials = ['token' => 'ghp_test'];
    $connection->save();

    $server = sites_server($this->organization->id);

    $this->post('/sites', sites_input([$server->id], ['source_connection_id' => $connection->id, 'repository' => 'acme/shop', 'branch' => 'main', 'push_to_deploy' => true]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('sites.warnings', []);

    $site = Site::query()->firstOrFail();
    $key = app(SourceControlGateway::class)->deployKey($site->deploy_key_id);

    expect($key->installed)->toBeTrue()
        ->and($key->publicKey)->toStartWith('ssh-ed25519 ')
        ->and(app(SiteDirectory::class)->forRepository($connection->id, 'acme/shop', 'main'))->toHaveCount(1);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/repos/acme/shop/keys') && $request['read_only'] === true);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/repos/acme/shop/hooks'));

    $credentials = app(SourceControlGateway::class)->checkoutCredentials($connection->id, 'acme/shop', $site->deploy_key_id);
    expect($credentials->usesSsh())->toBeTrue()->and($credentials->url)->toBe('git@github.com:acme/shop.git');
});
