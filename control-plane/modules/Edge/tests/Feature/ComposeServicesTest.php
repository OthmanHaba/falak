<?php

use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Domain\Models\Header;
use Kiln\Edge\Domain\Models\Redirect;
use Kiln\Edge\Domain\Models\SecurityRule;
use Kiln\Edge\Domain\Models\ServiceSetting;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Identity\Contracts\Role;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteFactory;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\ComposeServiceExtracted;

/*
 * Edge for every public service of a compose site (docs/plans/COMPOSE_APPS.md, phase 2), with the real Sites module:
 * domain rows per service, the read model mirrored back, rules and IP lists per service.
 */

require_once __DIR__.'/../../../Sites/tests/Support/helpers.php';

const COMPOSE_STACK = "services:\n  web:\n    image: nginx:1.27\n  admin:\n    image: ghcr.io/acme/admin:2\n    expose: ['9000']\n  api:\n    image: ghcr.io/acme/api:2\n    expose: ['8000']\n";

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->agents = sites_fake_agents();
    sites_fake_source_control();
    config(['sites.test_domain' => 'kiln.test']);
    $this->server = sites_server($this->organization->id, ['name' => 'app-1'], docker: true);
    $this->site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'stack', 'runtime' => 'compose', 'server_ids' => [$this->server->id], 'compose_source' => 'inline', 'compose_content' => COMPOSE_STACK,
        'public_services' => [
            ['service' => 'web', 'port' => 80, 'domain' => ['type' => 'custom', 'name' => 'stack.example.com']],
            ['service' => 'admin', 'port' => 9000, 'domain' => ['type' => 'custom', 'name' => 'admin.example.com'], 'health_check_path' => '/healthz'],
            ['service' => 'api', 'port' => 8000],
        ],
    ])->site;
    $this->base = "/sites/{$this->site->id}";
});

function compose_public(string $siteId): array
{
    return collect(app(SiteDirectory::class)->find($siteId)->compose->publicServices)->mapWithKeys(fn ($p) => [$p->service => $p->domain])->all();
}

it('turns the domains chosen at creation into rows per service', function () {
    expect(Domain::query()->where('site_id', $this->site->id)->orderBy('name')->get(['name', 'compose_service', 'is_primary'])->toArray())->toBe([
        ['name' => 'admin.example.com', 'compose_service' => 'admin', 'is_primary' => true],
        ['name' => 'stack.example.com', 'compose_service' => null, 'is_primary' => true],
    ])
        ->and(app(SiteDirectory::class)->find($this->site->id)->compose->publicServices[1]->healthCheckPath)->toBe('/healthz');

    $this->getJson("{$this->base}/domains")->assertOk()
        ->assertJsonPath('data.services.0', ['service' => 'web', 'primary' => true, 'port' => 80, 'test_domain' => 'stack.kiln.test', 'health_check_path' => null])
        ->assertJsonPath('data.services.1.service', 'admin')
        ->assertJsonPath('data.services.1.health_check_path', '/healthz')
        ->assertJsonPath('data.domains.0.name', 'admin.example.com')
        ->assertJsonPath('data.domains.0.service', 'admin')
        ->assertJsonPath('data.domains.1.service', null);
});

it('adds domains to a service, keeps a primary per service and mirrors the first one back', function () {
    $this->post("{$this->base}/domains", ['name' => 'api.example.com', 'service' => 'api'])->assertSessionHasNoErrors();
    $this->post("{$this->base}/domains", ['name' => 'ops.example.com', 'service' => 'admin'])->assertSessionHasNoErrors();
    // The primary service by name is the site itself.
    $this->post("{$this->base}/domains", ['name' => 'www2.example.com', 'service' => 'web'])->assertSessionHasNoErrors();
    $this->post("{$this->base}/domains", ['name' => 'x.example.com', 'service' => 'nope'])->assertSessionHasErrors('service');

    $rows = Domain::query()->where('site_id', $this->site->id)->get()->keyBy('name');
    expect($rows['api.example.com']->compose_service)->toBe('api')
        ->and($rows['api.example.com']->is_primary)->toBeTrue()
        ->and($rows['ops.example.com']->is_primary)->toBeFalse()
        ->and($rows['www2.example.com']->compose_service)->toBeNull()
        ->and($rows['www2.example.com']->is_primary)->toBeFalse()
        ->and(compose_public($this->site->id))->toBe(['web' => 'stack.example.com', 'admin' => 'admin.example.com', 'api' => 'api.example.com']);

    // Making another domain of the admin service primary leaves the site's primary alone.
    $this->put("{$this->base}/domains/{$rows['ops.example.com']->id}/primary")->assertSessionHasNoErrors();
    expect($rows['ops.example.com']->refresh()->is_primary)->toBeTrue()
        ->and($rows['admin.example.com']->refresh()->is_primary)->toBeFalse()
        ->and($rows['stack.example.com']->refresh()->is_primary)->toBeTrue()
        ->and(compose_public($this->site->id)['admin'])->toBe('ops.example.com');

    // Removing the api's only domain clears it in the read model; removing the admin's primary promotes the other.
    $this->delete("{$this->base}/domains/{$rows['api.example.com']->id}")->assertSessionHasNoErrors();
    $this->delete("{$this->base}/domains/{$rows['ops.example.com']->id}")->assertSessionHasNoErrors();
    expect($rows['admin.example.com']->refresh()->is_primary)->toBeTrue()
        ->and(compose_public($this->site->id))->toBe(['web' => 'stack.example.com', 'admin' => 'admin.example.com', 'api' => null]);

    $routeId = app(EdgeRoutes::class)->routeId($this->site->id);
    $payload = app(EdgeRoutes::class)->compile($this->server->id);
    $admin = collect($payload['sites'])->firstWhere('id', "{$routeId}-svc-admin");
    expect($admin['domains'])->toBe(['admin.example.com', 'admin-stack.kiln.test'])
        ->and(collect($payload['sites'])->firstWhere('id', $routeId)['domains'])->toBe(['stack.example.com', 'www2.example.com', 'stack.kiln.test'])
        ->and(collect(app(EdgeRoutes::class)->domainsFor($this->site->id, 'admin'))->pluck('name')->all())->toBe(['admin.example.com'])
        ->and(collect(app(EdgeRoutes::class)->domainsFor($this->site->id))->pluck('name')->all())->toBe(['stack.example.com', 'www2.example.com']);
});

it('generates a name for a service from the service and the site', function () {
    config(['edge.generated_domain_suffix' => 'sslip.io']);
    $this->post("{$this->base}/domains", ['type' => 'generated', 'service' => 'api'])->assertSessionHasNoErrors();

    $row = Domain::query()->where('site_id', $this->site->id)->where('compose_service', 'api')->first();
    expect($row?->name)->toStartWith('api-stack.')->toEndWith('.sslip.io');
});

it('imports a domain chosen later in Settings → Compose', function () {
    $this->putJson("{$this->base}/compose", [
        'compose_source' => 'inline',
        'compose_content' => COMPOSE_STACK,
        'public_services' => [
            ['service' => 'web', 'port' => 80, 'domain' => 'stack.example.com'],
            ['service' => 'admin', 'port' => 9000, 'domain' => 'admin.example.com'],
            ['service' => 'api', 'port' => 8000, 'domain' => ['type' => 'custom', 'name' => 'api.example.com']],
        ],
    ])->assertOk();

    expect(Domain::query()->where('name', 'api.example.com')->value('compose_service'))->toBe('api');
});

it('scopes redirects, basic auth, headers and IP lists to a service', function () {
    $this->post("{$this->base}/redirects", ['from' => '/old', 'to' => '/new', 'status' => 301, 'service' => 'admin'])->assertSessionHasNoErrors();
    // The same path for the whole site is another rule.
    $this->post("{$this->base}/redirects", ['from' => '/old', 'to' => '/', 'status' => 302])->assertSessionHasNoErrors();
    $this->post("{$this->base}/redirects", ['from' => '/old', 'to' => '/x', 'status' => 301, 'service' => 'admin'])->assertSessionHasErrors('from');
    $this->post("{$this->base}/security-rules", ['username' => 'ops', 'password' => 'correct horse', 'service' => 'admin'])->assertSessionHasNoErrors();
    $this->post("{$this->base}/headers", ['name' => 'X-Robots-Tag', 'value' => 'noindex', 'service' => 'admin'])->assertSessionHasNoErrors();
    $this->post("{$this->base}/headers", ['name' => 'X-Robots-Tag', 'value' => 'all'])->assertSessionHasNoErrors();
    $this->post("{$this->base}/headers", ['name' => 'X-A', 'value' => 'b', 'service' => 'nope'])->assertSessionHasErrors('service');
    $this->put("{$this->base}/edge-settings", ['allow_ips' => ['192.0.2.0/24'], 'deny_ips' => [], 'service' => 'admin'])->assertSessionHasNoErrors();

    expect(Redirect::query()->where('compose_service', 'admin')->count())->toBe(1)
        ->and(SecurityRule::query()->value('compose_service'))->toBe('admin')
        ->and(Header::query()->where('site_id', $this->site->id)->count())->toBe(2)
        ->and(ServiceSetting::for($this->site->id, 'admin')->allow_ips)->toBe(['192.0.2.0/24']);

    $this->getJson("{$this->base}/routing")->assertOk()
        ->assertJsonPath('data.services.1.service', 'admin')
        ->assertJsonPath('data.serviceSettings.admin.allow_ips', ['192.0.2.0/24'])
        ->assertJsonCount(2, 'data.headers');

    $routeId = app(EdgeRoutes::class)->routeId($this->site->id);
    $payload = app(EdgeRoutes::class)->compile($this->server->id);
    expect(app(ProtocolSchemas::class)->validateCommand('edge.caddy.apply', ProtocolSchemas::toJson($payload)))->toBe([]);
    $site = collect($payload['sites'])->firstWhere('id', $routeId);
    $admin = collect($payload['sites'])->firstWhere('id', "{$routeId}-svc-admin");
    $api = collect($payload['sites'])->firstWhere('id', "{$routeId}-svc-api");

    expect($site['headers'])->toBe(['X-Robots-Tag' => 'all'])
        ->and($site)->not->toHaveKeys(['basic_auth', 'allow_ips'])
        ->and($admin['headers'])->toBe(['X-Robots-Tag' => 'noindex'])
        ->and($admin['basic_auth'][0]['username'])->toBe('ops')
        ->and($admin['allow_ips'])->toBe(['192.0.2.0/24'])
        ->and(collect($admin['redirects'])->pluck('to')->all())->toBe(['/new', '/'])
        ->and($api)->not->toHaveKeys(['basic_auth', 'allow_ips']);

    // Empty lists remove the service's own settings.
    $this->put("{$this->base}/edge-settings", ['allow_ips' => [], 'deny_ips' => [], 'service' => 'admin'])->assertSessionHasNoErrors();
    expect(ServiceSetting::query()->count())->toBe(0);
});

it('forgets service rows with the site', function () {
    $this->put("{$this->base}/edge-settings", ['allow_ips' => ['192.0.2.0/24'], 'deny_ips' => [], 'service' => 'admin'])->assertSessionHasNoErrors();

    $this->delete("/sites/{$this->site->id}", ['name' => 'stack'])->assertSessionHasNoErrors();

    expect(Site::query()->find($this->site->id))->toBeNull()
        ->and(Domain::query()->where('site_id', $this->site->id)->count())->toBe(0)
        ->and(ServiceSetting::query()->count())->toBe(0);
});

it('moves a split-out service\'s domains and rules to its new site', function () {
    Redirect::query()->create(['site_id' => $this->site->id, 'compose_service' => 'admin', 'from' => '/old', 'to' => '/new', 'status' => 301, 'position' => 0]);
    ServiceSetting::query()->create(['site_id' => $this->site->id, 'service' => 'admin', 'allow_ips' => ['203.0.113.0/24'], 'deny_ips' => []]);
    $split = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'stack-admin', 'framework' => 'docker', 'runtime' => 'docker', 'docker_image' => 'ghcr.io/acme/admin:2', 'container_port' => 9000, 'server_ids' => [$this->server->id],
    ])->site;

    event(new ComposeServiceExtracted($this->site->id, $this->organization->id, 'admin', 'site', $split->id, 'stack-admin'));

    $moved = Domain::query()->where('name', 'admin.example.com')->firstOrFail();
    expect($moved->site_id)->toBe($split->id)
        ->and($moved->compose_service)->toBeNull()
        ->and(Redirect::query()->where('from', '/old')->value('site_id'))->toBe($split->id)
        ->and(ServiceSetting::query()->where('service', 'admin')->exists())->toBeFalse()
        ->and(Domain::query()->where('name', 'stack.example.com')->value('site_id'))->toBe($this->site->id);
});
