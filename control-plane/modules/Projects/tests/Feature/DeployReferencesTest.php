<?php

use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Projects\Application\Actions\LinkService;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Domain\Models\EnvironmentVersion;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Deployments/tests/Support/helpers.php';

/**
 * A DeployWorld whose site is placed in the default environment with the given variables as version 2.
 *
 * @param  array<string, string>  $variables
 */
function projects_deploy_world(array $variables): DeployWorld
{
    $world = deploy_world();
    $environment = projects_default_env($world->organization);
    app(LinkService::class)($environment, ServiceKind::Site, $world->site->id, 'web');

    EnvironmentVersion::query()->create([
        'site_id' => $world->site->id,
        'version' => 2,
        'variables' => $variables,
        'exposed' => ['DB_HOST'],
        'changed_keys' => [],
        'created_at' => now(),
    ]);

    return $world;
}

function projects_deploy(DeployWorld $world): Deployment
{
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    return $deployment->refresh();
}

it('renders resolved references into the release .env and the deploy script environment', function () {
    $world = projects_deploy_world(['APP_KEY' => 'base64:k', 'DATABASE_URL' => '${{ shop.DATABASE_URL }}', 'DB_HOST' => '${{ shop.DB_HOST }}']);
    [, , $engine] = projects_database($world->organization, 'shop', projects_default_env($world->organization));
    $host = Server::query()->find($engine->server_id)->private_ipv4;

    $deployment = projects_deploy($world);

    $env = $world->agents->last('deploy.prepare')['payload']['env_file']['content'];

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($env)->toContain("DATABASE_URL=\"postgresql://shop_user:p%40ss%2Fword@{$host}:5432/shop\"")
        ->toContain("DB_HOST={$host}")
        ->not->toContain('${{');

    $hooks = $world->agents->dispatched('deploy.hook');
    expect($hooks)->not->toBeEmpty()
        ->and((array) $hooks[0]['payload']['env'])->toMatchArray(['DB_HOST' => $host]);
});

it('fails the deployment with a clear error when a reference cannot be resolved', function () {
    $world = projects_deploy_world(['APP_KEY' => 'base64:k', 'DATABASE_URL' => '${{ missing.DATABASE_URL }}']);

    $deployment = projects_deploy($world);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('Unresolved variable references: DATABASE_URL: unknown service "missing" in ${{ missing.DATABASE_URL }}.')
        ->and($world->agents->dispatched('deploy.prepare'))->toBe([]);
});
