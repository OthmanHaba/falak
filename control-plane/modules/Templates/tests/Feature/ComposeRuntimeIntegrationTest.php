<?php

use Kiln\Deployments\Contracts\DeploymentDirectory;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteRuntime;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Deployments/tests/Support/helpers.php';

/*
 * End to end through the REAL Sites SiteFactory + Deployments trigger (no templates fakes). Runs only once the
 * compose runtime (lane A: Sites\Contracts\ComposeInspector + compose fields on SiteFactory) is present; until
 * then it is skipped. After lane A merges this must pass unchanged — if it needs edits, the §5 contract drifted.
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
        ->and($response->json('data.deployment_id'))->not->toBeNull()
        ->and(app(DeploymentDirectory::class)->currentForSites([$siteId]))->toHaveKey($siteId);
})->skip(fn () => ! interface_exists('Kiln\Sites\Contracts\ComposeInspector'), 'Needs the compose runtime (lane A): Sites\Contracts\ComposeInspector + SiteFactory compose fields.');
