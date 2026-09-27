<?php

use Kiln\Deployments\Contracts\DeploymentDirectory;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Sites\Contracts\ComposeSites;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteRuntime;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Deployments/tests/Support/helpers.php';

/*
 * End to end through the REAL Sites SiteFactory + Deployments trigger (no templates fakes): the §5 contract between
 * the templates engine and the compose runtime.
 */
it('deploys a catalog template through the real compose runtime', function () {
    templates_fixture_catalog();
    config(['sites.test_domain' => 'kiln.test']);
    $world = deploy_world(servers: 1);
    $project = Project::query()->where('organization_id', $world->organization->id)->where('is_default', true)->firstOrFail();

    $response = $this->postJson("/projects/{$project->id}/production/templates/hello/deploy", [
        'inputs' => ['ADMIN_EMAIL' => 'ops@example.com'],
        'domains' => ['admin' => 'admin.example.com'],
        'server_ids' => [$world->servers[0]->id],
    ])->assertCreated();

    $siteId = $response->json('data.site_id');
    $site = app(SiteDirectory::class)->find($siteId);
    $variables = app(SiteDirectory::class)->environment($siteId)->variables;

    expect($site->runtime)->toBe(SiteRuntime::Compose)
        ->and($variables['ADMIN_EMAIL'])->toBe('ops@example.com')
        ->and($variables['APP_SECRET'])->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($variables['PUBLIC_URL'])->toBe('https://hello-stack.kiln.test')
        ->and($site->compose->source->value)->toBe('inline')
        ->and($site->compose->version)->toBe(1)
        ->and($site->compose->template)->toBe(['slug' => 'hello', 'version' => '1.2.0', 'source' => 'catalog'])
        ->and(array_map(fn ($p) => [$p->service, $p->port, $p->domain], $site->compose->publicServices))->toBe([['web', 8080, null], ['admin', 9000, 'admin.example.com']])
        ->and($site->compose->publicServices[0]->hostPort)->not->toBeNull()
        ->and(app(ComposeSites::class)->content($siteId)->content)->toContain('services:')
        ->and($response->json('data.deployment_id'))->not->toBeNull()
        ->and(app(DeploymentDirectory::class)->currentForSites([$siteId]))->toHaveKey($siteId);
});
