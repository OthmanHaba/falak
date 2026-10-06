<?php

use Falak\Identity\Contracts\Role;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->owner, $this->organization] = actingAsMember();
    $this->server = Server::factory()->status(ServerStatus::Active)->create(['organization_id' => $this->organization->id, 'name' => 'app-1']);
});

function server_tabs_site(Server $server, string $name = 'Storefront'): Site
{
    $site = Site::query()->create([
        'organization_id' => $server->organization_id,
        'name' => $name,
        'slug' => strtolower($name),
        'runtime' => SiteRuntime::FrankenPhp,
        'build_mode' => BuildMode::Native,
        'framework' => Framework::Laravel,
        'php_version' => '8.4',
        'web_directory' => 'public',
        'unix_user' => strtolower($name),
        'isolated' => true,
        'repository' => 'acme/'.strtolower($name),
        'branch' => 'main',
        'deploy_script' => '$FALAK_FETCH',
        'laravel' => new LaravelSettings,
    ]);
    SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $server->id, 'role' => TargetRole::Leader, 'status' => TargetStatus::Ready]);

    return $site;
}

it('renders every Servers-owned tab with the shared server header', function (string $path, string $component) {
    $this->get("/servers/{$this->server->id}{$path}")->assertOk()->assertInertia(fn ($page) => $page
        ->component($component, false)
        ->where('server.id', $this->server->id)
        ->where('server.name', 'app-1')
        ->has('server.type_label')
        ->has('server.provider_label')
        ->has('server.agent'));
})->with([
    'overview' => ['', 'Servers/Show'],
    'metrics' => ['/metrics', 'Servers/Tabs/Metrics'],
    'processes' => ['/processes', 'Servers/Tabs/Processes'],
    'ssh keys' => ['/ssh-keys', 'Servers/Tabs/SshKeys'],
    'php' => ['/php', 'Servers/Tabs/Php'],
    'settings' => ['/settings', 'Servers/Tabs/Settings'],
]);

it('redirects /overview to the canonical server URL', function () {
    $this->get("/servers/{$this->server->id}/overview")->assertRedirect("/servers/{$this->server->id}");
});

it('hides the tabs of other organizations', function (string $path) {
    [$stranger] = memberOf();

    $this->actingAs($stranger)->get("/servers/{$this->server->id}{$path}")->assertNotFound();
})->with(['/metrics', '/processes', '/ssh-keys', '/php', '/settings', '/overview']);

it('serves metric samples as JSON and the Metrics tab as a page', function () {
    $this->getJson("/servers/{$this->server->id}/metrics?range=6h")->assertOk()->assertJsonPath('data', []);
    $this->get("/servers/{$this->server->id}/metrics")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Servers/Tabs/Metrics', false)
        ->where('ranges', ['1h', '6h', '24h'])
        ->where('telemetry', true));
});

it('lists the services running on a server with their canvas deep links', function () {
    $site = server_tabs_site($this->server);
    $this->artisan('projects:backfill', ['--organization' => $this->organization->id])->assertSuccessful();

    $this->get("/servers/{$this->server->id}")->assertInertia(fn ($page) => $page
        ->has('services', 1)
        ->where('services.0.kind', 'site')
        ->where('services.0.id', $site->id)
        ->where('services.0.icon', 'laravel')
        ->where('services.0.role', 'leader')
        ->where('services.0.url', fn (string $url) => str_starts_with($url, '/projects/') && str_ends_with($url, "/service/site/{$site->id}")));

    $this->get('/servers')->assertInertia(fn ($page) => $page
        ->where('servers.0.services.0.name', 'Storefront')
        ->missing('sparklines'));

    $this->get("/servers/{$this->server->id}/processes")->assertInertia(fn ($page) => $page
        ->where('sites.0.id', $site->id)
        ->where('can.view', true));
});

it('loads fleet sparklines as a deferred prop', function () {
    $this->get('/servers')->assertInertia(fn ($page) => $page->missing('sparklines'));
    $version = (string) $this->get('/servers')->viewData('page')['version'];

    $this->get('/servers', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Servers/Index', 'X-Inertia-Partial-Data' => 'sparklines'])
        ->assertOk()
        ->assertJsonPath('props.sparklines', []);
});

it('renames the server and changes its timezone from Settings', function () {
    $this->patch("/servers/{$this->server->id}", ['timezone' => 'Europe/Berlin'])->assertSessionHasNoErrors()->assertRedirect();
    expect($this->server->refresh()->timezone)->toBe('Europe/Berlin');

    $this->patch("/servers/{$this->server->id}", ['name' => 'app-primary'])->assertSessionHasNoErrors();
    expect($this->server->refresh()->name)->toBe('app-primary');

    $this->patch("/servers/{$this->server->id}", ['timezone' => 'Mars/Olympus'])->assertSessionHasErrors('timezone');
    $this->patch("/servers/{$this->server->id}", [])->assertSessionHasErrors('name');
});

it('shows the install command in Settings only to agent managers', function () {
    $this->server->forceFill(['install_command' => 'curl -fsSL https://panel.falak.test/install/abc | sudo bash'])->save();
    [$developer] = memberOf($this->organization, Role::Developer);

    $this->get("/servers/{$this->server->id}/settings")->assertInertia(fn ($page) => $page
        ->where('server.install_command', fn ($command) => str_starts_with((string) $command, 'curl '))
        ->where('can.regenerateInstallCommand', true)
        ->where('can.delete', true));

    $this->actingAs($developer)->get("/servers/{$this->server->id}/settings")->assertInertia(fn ($page) => $page
        ->where('server.install_command', null)
        ->where('can.regenerateInstallCommand', false)
        ->where('can.delete', false));
});

it('ships a page for every Inertia component the infrastructure controllers render', function () {
    $root = dirname(__DIR__, 3);
    $missing = [];

    foreach (['Servers', 'Network', 'Terminal', 'Recipes'] as $module) {
        foreach (glob("{$root}/{$module}/src/Http/Controllers/*.php") ?: [] as $file) {
            preg_match_all("/Inertia::render\\('([A-Za-z]+)\\/([A-Za-z\\/]+)'/", (string) file_get_contents($file), $matches, PREG_SET_ORDER);

            foreach ($matches as [, $owner, $page]) {
                if (! is_file("{$root}/{$owner}/resources/js/pages/{$page}.tsx")) {
                    $missing[] = "{$owner}/{$page}";
                }
            }
        }
    }

    expect($missing)->toBe([]);
});
