<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Sites\Contracts\TargetStatus;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Viewer);
    $this->environment = projects_default_env($this->organization);
    $this->url = "/projects/{$this->environment->project_id}/production";
    config(['sites.test_domain' => 'kiln.test']);
});

it('returns every service of the environment with live status, servers and reference edges', function () {
    $web1 = sites_server($this->organization->id, ['name' => 'web-1']);
    $web2 = sites_server($this->organization->id, ['name' => 'web-2']);
    sites_fake_agent_memory([$web1->id => 1]); // web-1 online, web-2 without agent

    [$database, , $engine] = projects_database($this->organization, 'shop', $this->environment);
    $shop = projects_site($this->organization, 'Storefront', ['DATABASE_URL' => '${{ shop.DATABASE_URL }}', 'API' => '${{ api.URL }}'], $this->environment, [$web1, $web2], ['test_domain_enabled' => true]);
    $api = projects_site($this->organization, 'Api', ['URL' => 'x', 'DB' => '${{ shop.DB_HOST }}'], $this->environment, [$web1], ['framework' => 'node', 'runtime' => 'bun', 'php_version' => null]);

    // Shop: succeeded 2 minutes ago; the older failure is superseded.
    projects_deployment($shop, 'failed', ['finished_at' => now()->subHour()]);
    $done = projects_deployment($shop, 'succeeded', ['finished_at' => now()->subMinutes(2)]);
    // Api: deploying, 2 of 3 forward steps done.
    $running = projects_deployment($api, 'deploying', ['phase' => 'prepare']);
    projects_steps($running, ['succeeded', 'skipped', 'running']);

    $serviceIds = Service::query()->pluck('id', 'ref_id');
    $engineServer = Server::query()->find($engine->server_id);

    $response = $this->getJson("{$this->url}/canvas")->assertOk();

    expect($response->json('services'))->toHaveCount(3)
        ->and($response->json('services.0'))->toBe([
            'id' => $serviceIds[$database->id],
            'kind' => 'database',
            'ref_id' => $database->id,
            'name' => 'shop',
            'position' => ['x' => 0, 'y' => 0],
            'icon' => 'postgresql',
            'status' => 'active',
            'status_label' => 'Active',
            'url' => null,
            'subtitle' => 'PostgreSQL 16 · '.$engineServer->name,
            'servers' => [['id' => $engineServer->id, 'name' => $engineServer->name, 'leader' => false, 'online' => false]],
            'last_deployment' => null,
        ])
        ->and($response->json('services.1'))->toBe([
            'id' => $serviceIds[$shop->id],
            'kind' => 'site',
            'ref_id' => $shop->id,
            'name' => 'Storefront',
            'position' => ['x' => 300, 'y' => 0],
            'icon' => 'laravel',
            'status' => 'active',
            'status_label' => 'Active · 2m ago',
            'url' => "https://{$shop->slug}.kiln.test",
            'subtitle' => 'Laravel · PHP 8.4',
            'servers' => [
                ['id' => $web1->id, 'name' => 'web-1', 'leader' => true, 'online' => true],
                ['id' => $web2->id, 'name' => 'web-2', 'leader' => false, 'online' => false],
            ],
            'last_deployment' => [
                'id' => $done->id,
                'status' => 'succeeded',
                'commit' => 'bbbbbbb',
                'message' => 'Ship it',
                'finished_at' => $done->finished_at->toIso8601String(),
            ],
        ])
        ->and($response->json('services.2.icon'))->toBe('bun')
        ->and($response->json('services.2.status'))->toBe('deploying')
        ->and($response->json('services.2.status_label'))->toBe('Deploying 66%')
        ->and($response->json('services.2.last_deployment.finished_at'))->toBeNull()
        ->and($response->json('edges'))->toEqualCanonicalizing([
            ['from' => $serviceIds[$shop->id], 'to' => $serviceIds[$database->id]],
            ['from' => $serviceIds[$shop->id], 'to' => $serviceIds[$api->id]],
            ['from' => $serviceIds[$api->id], 'to' => $serviceIds[$database->id]],
        ]);
});

it('derives statuses from targets and deployments', function (?string $deployment, TargetStatus $target, bool $servers, string $status, string $label) {
    $server = sites_server($this->organization->id);
    $site = projects_site($this->organization, 'Shop', [], $this->environment, $servers ? [$server] : [], targetStatus: $target);

    if ($deployment !== null) {
        projects_deployment($site, $deployment, ['finished_at' => now()->subMinutes(5)]);
    }

    $this->getJson("{$this->url}/canvas")
        ->assertJsonPath('services.0.status', $status)
        ->assertJsonPath('services.0.status_label', $label);
})->with([
    'never deployed' => [null, TargetStatus::Ready, true, 'inactive', 'Not deployed'],
    'no servers' => [null, TargetStatus::Ready, false, 'inactive', 'No servers'],
    'provisioning' => [null, TargetStatus::Provisioning, true, 'provisioning', 'Provisioning'],
    'target failed' => ['succeeded', TargetStatus::Failed, true, 'failed', 'Server setup failed'],
    'queued' => ['queued', TargetStatus::Ready, true, 'queued', 'Queued'],
    'building' => ['building', TargetStatus::Ready, true, 'building', 'Building'],
    'failed' => ['failed', TargetStatus::Ready, true, 'failed', 'Failed · 5m ago'],
    'cancelled' => ['cancelled', TargetStatus::Ready, true, 'inactive', 'Cancelled · 5m ago'],
]);

it('shows pending databases as provisioning', function () {
    [$database] = projects_database($this->organization, 'shop', $this->environment);
    $database->forceFill(['status' => 'pending'])->save();

    $this->getJson("{$this->url}/canvas")
        ->assertJsonPath('services.0.status', 'provisioning')
        ->assertJsonPath('services.0.status_label', 'Creating');
});

it('accepts environment ids as well as slugs and 404s unknown environments', function () {
    $this->getJson("/projects/{$this->environment->project_id}/{$this->environment->id}/canvas")->assertOk()->assertExactJson(['services' => [], 'edges' => []]);
    $this->getJson("/projects/{$this->environment->project_id}/nope/canvas")->assertNotFound();
});

it('renders the canvas page with project, environment, canvas and panel props', function () {
    $site = projects_site($this->organization, 'Shop', [], $this->environment);

    $this->get($this->url)->assertOk()->assertInertia(fn ($page) => $page
        ->component('Projects/Canvas', false)
        ->where('project.id', $this->environment->project_id)
        ->where('project.is_default', true)
        ->where('environment.slug', 'production')
        ->where('environment.is_production', true)
        ->has('canvas.services', 1)
        ->has('canvas.edges', 0)
        ->where('panel', null)
        ->where('can.manage', false)
        ->where('kiln.current.project_id', $this->environment->project_id)
        ->where('kiln.current.environment_id', $this->environment->id));

    $this->get("{$this->url}/service/site/{$site->id}/variables")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Projects/Canvas', false)
        ->where('panel', ['kind' => 'site', 'id' => $site->id, 'tab' => 'variables', 'item' => null]));

    $this->get("{$this->url}/service/site/{$site->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('panel', ['kind' => 'site', 'id' => $site->id, 'tab' => null, 'item' => null]));

    $this->get("{$this->url}/service/site/{$site->id}/deployments/01JDEPLOYMENT0000000000000")->assertOk()->assertInertia(fn ($page) => $page
        ->where('panel', ['kind' => 'site', 'id' => $site->id, 'tab' => 'deployments', 'item' => '01jdeployment0000000000000']));

    $this->get("{$this->url}/service/database/{$site->id}")->assertNotFound();
});

it('opens the production canvas for a project url and serves project JSON', function () {
    $this->get("/projects/{$this->environment->project_id}")->assertRedirect($this->url);
    $this->getJson("/projects/{$this->environment->project_id}")->assertOk()
        ->assertJsonPath('data.name', 'Default')
        ->assertJsonPath('data.environments.0.slug', 'production')
        ->assertJsonPath('data.environments.0.services_count', 0);
});
