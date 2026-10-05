<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Falak\Edge\Application\Jobs\ApplyEdgeConfig;
use Falak\Edge\Application\Listeners\ReactToSiteChanges;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\LbPolicy;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\Header;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Edge\Domain\Models\Redirect;
use Falak\Edge\Domain\Models\SecurityRule;
use Falak\Edge\Infrastructure\RouteCompiler;
use Falak\Fleet\Events\CommandFinished;
use Falak\Processes\Contracts\OctaneRouting;
use Falak\Processes\Events\OctaneRoutingChanged;
use Falak\Servers\Contracts\ServerType;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\OctaneServer;
use Falak\Sites\Contracts\SiteRuntime;

require_once __DIR__.'/../Support/helpers.php';

/** Octane routing answers controlled by the test: "<site>:<server>" => port. */
final class EdgeFakeOctaneRouting implements OctaneRouting
{
    /** @var array<string, int> */
    public array $listening = [];

    public function listeningPort(string $siteId, string $serverId): ?int
    {
        return $this->listening[strtolower("{$siteId}:{$serverId}")] ?? null;
    }
}

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers, 'agents' => $this->agents] = edge_fakes();
    $this->octane = new EdgeFakeOctaneRouting;
    app()->instance(OctaneRouting::class, $this->octane);
    $this->org = (string) Str::ulid();
    $this->web = edge_server($this->servers, $this->org, ['privateIpv4' => '10.0.0.2']);
    $this->laravel = new LaravelSettings(octane: true, octaneServer: OctaneServer::FrankenPhp, octanePort: 8123);
});

function edge_octane_site(object $test, array $overrides = [])
{
    $site = edge_site($test->sites, $test->org, [$test->web->id], ['slug' => 'shop', 'laravel' => $test->laravel, 'testDomain' => 'shop.falak.test', ...$overrides]);
    Domain::query()->create(['organization_id' => $test->org, 'site_id' => $site->id, 'name' => ($overrides['slug'] ?? 'shop').'.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::ToApex, 'tls_mode' => TlsMode::Auto]);

    return $site;
}

it('serves an Octane site directly until Processes verified Octane listening, then reverse-proxies to it', function () {
    $site = edge_octane_site($this);
    $id = strtolower($site->id);

    // Never deployed / starting: FrankenPHP keeps serving (the placeholder release) directly.
    expect(edge_entry(edge_compile($this->web->id), $id))->toMatchArray(['kind' => 'frankenphp', 'root' => '/srv/falak/sites/shop/current/public']);

    $this->octane->listening[strtolower("{$site->id}:{$this->web->id}")] = 8123;
    $entry = edge_entry(edge_compile($this->web->id), $id);

    expect($entry)->toMatchArray([
        'kind' => 'reverse_proxy',
        'root' => '/srv/falak/sites/shop/current/public',
        'upstreams' => [['dial' => '127.0.0.1:8123']],
        'try_duration_s' => 30,
        'domains' => ['shop.com', 'shop.falak.test'],
        'redirect_domains' => ['www.shop.com'],
    ])->and($entry)->not->toHaveKeys(['php_fpm_socket', 'health_uri'])
        ->and(RouteCompiler::octaneSites(edge_compile($this->web->id)))->toBe([$id]);
});

it('never proxies to a port Octane is not verified on (port moved) and ignores Octane when it is off', function () {
    $site = edge_octane_site($this);
    $this->octane->listening[strtolower("{$site->id}:{$this->web->id}")] = 8999;

    expect(edge_entry(edge_compile($this->web->id), strtolower($site->id))['kind'])->toBe('frankenphp');

    $off = edge_octane_site($this, ['slug' => 'off', 'laravel' => new LaravelSettings(octane: false, octaneServer: OctaneServer::FrankenPhp, octanePort: 8124), 'testDomain' => 'off.falak.test']);
    $this->octane->listening[strtolower("{$off->id}:{$this->web->id}")] = 8124;

    expect(edge_entry(edge_compile($this->web->id), strtolower($off->id))['kind'])->toBe('frankenphp');
});

it('keeps TLS, redirects, headers, basic auth and IP rules on Octane routes (php-fpm sites too)', function () {
    $site = edge_octane_site($this, ['runtime' => SiteRuntime::PhpFpm, 'laravel' => new LaravelSettings(octane: true, octaneServer: OctaneServer::Swoole, octanePort: 8200)]);
    Header::query()->create(['site_id' => $site->id, 'name' => 'X-Frame-Options', 'value' => 'DENY']);
    SecurityRule::query()->create(['site_id' => $site->id, 'username' => 'ops', 'password_hash' => '$2y$10$abc']);
    Redirect::query()->create(['site_id' => $site->id, 'from' => '/old', 'to' => '/new', 'status' => 301, 'position' => 0]);
    $this->octane->listening[strtolower("{$site->id}:{$this->web->id}")] = 8200;

    expect(edge_entry(edge_compile($this->web->id), strtolower($site->id)))->toMatchArray([
        'kind' => 'reverse_proxy',
        'upstreams' => [['dial' => '127.0.0.1:8200']],
        'tls' => ['mode' => 'acme'],
        'headers' => ['X-Frame-Options' => 'DENY'],
        'basic_auth' => [['username' => 'ops', 'password_hash' => '$2y$10$abc']],
        'redirects' => [['from' => '/old', 'to' => '/new', 'status' => 301]],
    ]);
});

it('proxies to Octane on load-balanced backends (plain HTTP) while the LB keeps proxying to the backends', function () {
    $lb = edge_server($this->servers, $this->org, ['type' => ServerType::LoadBalancer]);
    $site = edge_octane_site($this);
    LoadBalancer::query()->create(['organization_id' => $this->org, 'site_id' => $site->id, 'server_id' => $lb->id, 'policy' => LbPolicy::RoundRobin, 'backend_port' => 80, 'weights' => []]);
    $this->octane->listening[strtolower("{$site->id}:{$this->web->id}")] = 8123;
    $id = strtolower($site->id);

    expect(edge_entry(edge_compile($this->web->id), $id))->toMatchArray(['kind' => 'reverse_proxy', 'tls' => ['mode' => 'off'], 'upstreams' => [['dial' => '127.0.0.1:8123']]])
        ->and(edge_entry(edge_compile($lb->id), $id))->toMatchArray(['kind' => 'reverse_proxy', 'upstreams' => [['dial' => '10.0.0.2:80']]]);
});

it('reports whether the applied or a pending config proxies to Octane', function () {
    $site = edge_octane_site($this);
    $routes = app(EdgeRoutes::class);
    $this->octane->listening[strtolower("{$site->id}:{$this->web->id}")] = 8123;

    expect($routes->proxiesToOctane($site->id, $this->web->id))->toBeFalse();

    $handle = $routes->apply($this->web->id);
    // Pending: the new config may already run.
    expect($routes->proxiesToOctane($site->id, $this->web->id))->toBeTrue();

    event(new CommandFinished($handle->id, $this->org, $this->web->id, 'edge.caddy.apply', 'key', 0, ['changed' => true, 'config_sha256' => str_repeat('a', 64), 'routes' => 1]));
    expect($routes->proxiesToOctane($site->id, $this->web->id))->toBeTrue();

    // Octane switched off: until the direct-serving config is applied, the edge still proxies.
    $this->octane->listening = [];
    $handle = $routes->apply($this->web->id);
    expect($routes->proxiesToOctane($site->id, $this->web->id))->toBeTrue();

    event(new CommandFinished($handle->id, $this->org, $this->web->id, 'edge.caddy.apply', 'key', 0, ['changed' => true, 'config_sha256' => str_repeat('b', 64), 'routes' => 1]));
    expect($routes->proxiesToOctane($site->id, $this->web->id))->toBeFalse();
});

it('re-applies the server edge when Octane routing changes', function () {
    Queue::fake();
    $site = edge_octane_site($this);

    expect(Event::hasListeners(OctaneRoutingChanged::class))->toBeTrue();
    app(ReactToSiteChanges::class)->octaneRoutingChanged(new OctaneRoutingChanged($site->id, $this->web->id, $this->org, 8123));

    Queue::assertPushed(ApplyEdgeConfig::class, fn (ApplyEdgeConfig $job) => $job->serverId === $this->web->id);
});
