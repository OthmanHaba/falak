<?php

use Illuminate\Support\Facades\Event;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Identity\Contracts\Role;
use Kiln\Processes\Application\Jobs\PollProcessStatus;
use Kiln\Processes\Application\OctaneRoutes;
use Kiln\Processes\Application\ServerConverger;
use Kiln\Processes\Contracts\OctaneRouting;
use Kiln\Processes\Contracts\ProcessControl;
use Kiln\Processes\Domain\Enums\OctaneRouteStatus;
use Kiln\Processes\Domain\Models\OctaneRoute;
use Kiln\Processes\Events\OctaneRoutingChanged;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = processes_fake_agents();
    $this->web = processes_server($this->organization->id, 'web1');
    $this->converger = app(ServerConverger::class);
    $this->routing = app(OctaneRouting::class);
    config(['sites.test_domain' => 'kiln.test']);
});

/** Settle the last proc.apply / edge.caddy.apply sent to the server. */
function octane_settle(object $test, string $type): void
{
    $test->agents->succeed($test->agents->last($type, $test->web->id)['handle'], $type === 'edge.caddy.apply'
        ? ['changed' => true, 'config_sha256' => str_repeat('a', 64), 'routes' => 1]
        : ['changed' => true]);
}

function octane_edge_kind(object $test, string $siteId): ?string
{
    foreach (app(EdgeRoutes::class)->compile($test->web->id)['sites'] as $entry) {
        if ($entry['id'] === strtolower($siteId)) {
            return $entry['kind'].(isset($entry['root']) && $entry['kind'] === 'reverse_proxy' ? ':octane' : '');
        }
    }

    return null;
}

function octane_toggle(object $test, Site $site, bool $on, ?string $server = null): void
{
    $test->put("/sites/{$site->id}/laravel", array_filter(['scheduler' => false, 'horizon' => false, 'octane' => $on, 'maintenance' => false, 'octane_server' => $server], fn ($v) => $v !== null))
        ->assertSessionHasNoErrors();
}

it('enables Octane in order: program started, probe answered, then the edge proxies', function () {
    $site = processes_site($this->organization->id, [$this->web], ['test_domain_enabled' => true]);

    octane_toggle($this, $site, true);
    $settings = $site->refresh()->laravel;
    $port = $settings->octanePort;

    expect($settings->octaneServer?->value)->toBe('frankenphp')
        ->and($port)->toBeGreaterThanOrEqual(8000)->toBeLessThan(9000)
        ->and(processes_programs($this->agents->last('proc.apply'))['shop.octane']['command'])
        ->toBe(['php8.4', 'artisan', 'octane:start', '--server=frankenphp', '--host=127.0.0.1', "--port={$port}", '--admin-port='.($port + 10000)])
        // Not verified yet: the edge still serves the site with FrankenPHP directly.
        ->and(OctaneRoute::query()->sole()->status)->toBe(OctaneRouteStatus::Starting)
        ->and($this->routing->listeningPort($site->id, $this->web->id))->toBeNull()
        ->and(octane_edge_kind($this, $site->id))->toBe('frankenphp')
        ->and($this->agents->dispatched('system.exec'))->toBe([]);

    // proc.apply applied → the probe waits for an HTTP answer on the port.
    octane_settle($this, 'proc.apply');
    $probe = $this->agents->last('system.exec', $this->web->id);
    expect(processes_schema_errors($probe))->toBe([])
        ->and($probe['payload']['script'])->toContain("http://127.0.0.1:{$port}/", '/dev/tcp/127.0.0.1/'.$port)
        ->and($probe['payload']['user'])->toBe('shop')
        ->and($probe['handle']->idempotencyKey)->toStartWith(OctaneRoutes::PROBE_PREFIX);

    $this->agents->succeed($probe['handle'], ['exit_code' => 0]);

    expect(OctaneRoute::query()->sole()->status)->toBe(OctaneRouteStatus::Listening)
        ->and($this->routing->listeningPort($site->id, $this->web->id))->toBe($port)
        ->and(octane_edge_kind($this, $site->id))->toBe('reverse_proxy:octane')
        ->and(collect($this->agents->last('edge.caddy.apply', $this->web->id)['payload']['sites'])->firstWhere('id', strtolower($site->id)))
        ->toMatchArray(['kind' => 'reverse_proxy', 'upstreams' => [['dial' => "127.0.0.1:{$port}"]], 'root' => '/srv/kiln/sites/shop/current/public']);
});

it('keeps serving a never-deployed site directly until its first release runs Octane', function () {
    $site = processes_site($this->organization->id, [$this->web], ['test_domain_enabled' => true], deployed: false);
    octane_toggle($this, $site, true);

    // No release yet: no program, nothing probed, the edge serves the placeholder release directly.
    expect(OctaneRoute::query()->sole()->status)->toBe(OctaneRouteStatus::Starting)
        ->and($this->agents->dispatched('proc.apply'))->toBe([])
        ->and($this->agents->dispatched('system.exec'))->toBe([])
        ->and(octane_edge_kind($this, $site->id))->toBe('frankenphp');
    app()->call([new PollProcessStatus, 'handle']);
    expect($this->agents->dispatched('system.exec'))->toBe([]);

    // First deploy: activation makes the release live, the restart step converges → Octane starts → probed.
    processes_deploy($site, [$this->web]);
    app(ProcessControl::class)->restartForSite($site->id, $this->web->id, newRelease: true);
    expect(processes_programs($this->agents->last('proc.apply')))->toHaveKey('shop.octane');
    octane_settle($this, 'proc.apply');

    // It crashed on start (e.g. missing extension): the probe fails and the edge keeps serving directly.
    $this->agents->fail($this->agents->last('system.exec')['handle'], 'Octane is not answering', 1);
    expect(OctaneRoute::query()->sole())->status->toBe(OctaneRouteStatus::Failed)->error->toContain('not answering')
        ->and(octane_edge_kind($this, $site->id))->toBe('frankenphp');

    // The periodic poll probes it again; once it answers, the edge proxies to it.
    app()->call([new PollProcessStatus, 'handle']);
    $this->agents->succeed($this->agents->last('system.exec')['handle'], ['exit_code' => 0]);

    expect(octane_edge_kind($this, $site->id))->toBe('reverse_proxy:octane');
});

it('restarts Octane on a new release through the converge (never octane:reload) and keeps it proxied', function () {
    $site = processes_site($this->organization->id, [$this->web], ['test_domain_enabled' => true]);
    octane_toggle($this, $site, true);
    octane_settle($this, 'proc.apply');
    $this->agents->succeed($this->agents->last('system.exec')['handle'], ['exit_code' => 0]);
    $before = processes_programs($this->agents->last('proc.apply'))['shop.octane'];

    $release = processes_deploy($site, [$this->web]);
    $handles = app(ProcessControl::class)->restartForSite($site->id, $this->web->id, newRelease: true);
    $after = processes_programs($this->agents->last('proc.apply'))['shop.octane'];

    expect($handles)->toHaveCount(1)
        ->and($handles[0]->type)->toBe('proc.apply')
        ->and($after['env']['KILN_RELEASE_ID'])->toBe(strtoupper($release->id))->not->toBe($before['env']['KILN_RELEASE_ID'])
        ->and(collect($this->agents->dispatched('system.exec'))->pluck('payload.script')->filter(fn ($s) => str_contains($s, 'octane:reload')))->toBeEmpty()
        // Still verified: the edge keeps proxying (it retries while Octane restarts).
        ->and($this->routing->listeningPort($site->id, $this->web->id))->toBe($site->refresh()->laravel->octanePort);
});

it('disables Octane in order: the edge switches back first, the program stops once that config is applied', function () {
    $site = processes_site($this->organization->id, [$this->web], ['test_domain_enabled' => true]);
    octane_toggle($this, $site, true);
    octane_settle($this, 'proc.apply');
    $this->agents->succeed($this->agents->last('system.exec')['handle'], ['exit_code' => 0]);
    octane_settle($this, 'edge.caddy.apply');
    expect(app(EdgeRoutes::class)->proxiesToOctane($site->id, $this->web->id))->toBeTrue();

    $procApplies = count($this->agents->dispatched('proc.apply'));
    octane_toggle($this, $site, false);

    // Draining: the new edge config serves the site directly, but Octane keeps running until it is applied.
    expect(OctaneRoute::query()->sole()->status)->toBe(OctaneRouteStatus::Draining)
        ->and(octane_edge_kind($this, $site->id))->toBe('frankenphp')
        ->and(collect($this->agents->last('edge.caddy.apply')['payload']['sites'])->firstWhere('id', strtolower($site->id))['kind'])->toBe('frankenphp')
        ->and(array_keys(processes_programs($this->agents->last('proc.apply'))))->toContain('shop.octane')
        ->and(count($this->agents->dispatched('proc.apply')))->toBe($procApplies);

    // A status poll before the edge applied does not stop it.
    app()->call([new PollProcessStatus, 'handle']);
    expect(OctaneRoute::query()->count())->toBe(1);

    octane_settle($this, 'edge.caddy.apply');

    expect(OctaneRoute::query()->count())->toBe(0)
        ->and(array_keys(processes_programs($this->agents->last('proc.apply'))))->not->toContain('shop.octane');
});

it('switches a never-verified Octane off without waiting for the edge', function () {
    $site = processes_site($this->organization->id, [$this->web], ['test_domain_enabled' => true]);
    octane_toggle($this, $site, true);
    octane_settle($this, 'proc.apply');

    octane_toggle($this, $site, false);

    expect(OctaneRoute::query()->count())->toBe(0)
        ->and(processes_programs($this->agents->last('proc.apply')))->not->toHaveKey('shop.octane');
});

it('reloads Octane gracefully when restarted from the UI, restarts it on deploys, and falls back to a restart', function () {
    $site = processes_site($this->organization->id, [$this->web], ['test_domain_enabled' => true]);
    octane_toggle($this, $site, true);
    octane_settle($this, 'proc.apply');
    $this->agents->succeed($this->agents->last('system.exec')['handle'], ['exit_code' => 0]);

    // Deploy (new release): octane:reload would keep the old release → proc.restart.
    app(ProcessControl::class)->restartForSite($site->id, $this->web->id, newRelease: true);
    expect($this->agents->last('proc.restart')['payload']['names'])->toBe(['shop.octane']);

    // UI restart (same release): graceful octane:reload, nothing hard-restarted.
    $restarts = count($this->agents->dispatched('proc.restart'));
    $this->post("/sites/{$site->id}/processes/restart", [], ['Accept' => 'application/json'])->assertOk()->assertJson(['data' => ['commands' => 1]]);
    $reload = $this->agents->last('system.exec');

    expect($reload['payload'])->toMatchArray(['script' => 'php8.4 artisan octane:reload --server=frankenphp', 'user' => 'shop', 'cwd' => '/srv/kiln/sites/shop/current'])
        ->and(count($this->agents->dispatched('proc.restart')))->toBe($restarts);

    // octane:reload failed (e.g. no server state file): restart the program instead.
    $this->agents->fail($reload['handle'], 'Octane server is not running.', 1);
    expect(count($this->agents->dispatched('proc.restart')))->toBe($restarts + 1)
        ->and($this->agents->last('proc.restart')['payload'])->toBe(['names' => ['shop.octane'], 'site' => 'shop']);
});

it('moves Octane back to Starting when its server changes and validates the server choice', function () {
    $site = processes_site($this->organization->id, [$this->web], ['runtime' => SiteRuntime::PhpFpm]);

    $this->put("/sites/{$site->id}/laravel", ['scheduler' => false, 'horizon' => false, 'octane' => true, 'maintenance' => false, 'octane_server' => 'frankenphp'])
        ->assertSessionHasErrors('octane_server');

    octane_toggle($this, $site, true);
    expect($site->refresh()->laravel->octaneServer?->value)->toBe('swoole');

    octane_settle($this, 'proc.apply');
    $this->agents->succeed($this->agents->last('system.exec')['handle'], ['exit_code' => 0]);
    expect(OctaneRoute::query()->sole()->status)->toBe(OctaneRouteStatus::Listening);

    Event::fake([OctaneRoutingChanged::class]);
    octane_toggle($this, $site, true, 'roadrunner');

    expect(OctaneRoute::query()->sole())->status->toBe(OctaneRouteStatus::Starting)->octane_server->value->toBe('roadrunner')
        ->and($this->routing->listeningPort($site->id, $this->web->id))->toBeNull();
    Event::assertDispatched(OctaneRoutingChanged::class, fn ($e) => $e->port === null);
});

it('reports Octane server, port and per-server routing state for the settings section', function () {
    $site = processes_site($this->organization->id, [$this->web], ['test_domain_enabled' => true]);

    $this->getJson("/sites/{$site->id}/processes/octane")->assertOk()->assertJson(['data' => ['enabled' => false, 'servers' => []]]);

    octane_toggle($this, $site, true);
    $port = $site->refresh()->laravel->octanePort;

    $this->getJson("/sites/{$site->id}/processes/octane")->assertOk()->assertJson(['data' => [
        'enabled' => true, 'server' => 'frankenphp', 'server_label' => 'FrankenPHP', 'port' => $port, 'aux_port' => $port + 10000,
        'servers' => [['server_id' => $this->web->id, 'status' => 'starting', 'port' => $port]],
    ]]);

    octane_settle($this, 'proc.apply');
    $this->agents->succeed($this->agents->last('system.exec')['handle'], ['exit_code' => 0]);

    expect($this->getJson("/sites/{$site->id}/processes/octane")->json('data.servers.0.status'))->toBe('listening');
});
