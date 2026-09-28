<?php

use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Contracts\LiveReleases;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Processes\Contracts\ProcessControl;
use Kiln\Processes\Infrastructure\AgentProcessControl;
use Kiln\Sites\Contracts\SiteDirectory;

require_once __DIR__.'/../Support/helpers.php';

/** A Node site deployed with the real Processes (not FakeProcessControl), so the restart step converges proc.apply. */
function live_world(int $servers = 1): DeployWorld
{
    $world = deploy_world(servers: $servers, site: ['runtime' => 'node', 'framework' => 'node', 'php_version' => null, 'app_port' => 3001, 'laravel' => [],
        'deploy_script' => "\$KILN_FETCH\n\$KILN_ACTIVATE\n\$KILN_RESTART_PROCS\n"]);
    app()->instance(ProcessControl::class, app(AgentProcessControl::class));

    return $world;
}

function live_deploy(DeployWorld $world, Trigger $trigger = Trigger::Manual, ?string $releaseId = null): Deployment
{
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), $trigger, releaseId: $releaseId);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    return $deployment->refresh();
}

it('starts a never-deployed site\'s app on its first activation with the release ids and site variables', function () {
    $world = live_world();
    $server = $world->servers[0]->id;

    expect(app(LiveReleases::class)->onServer($server))->toBe([]);

    $deployment = live_deploy($world);
    expect($deployment->status)->toBe(DeploymentStatus::Succeeded);

    // Restart step: no proc.restart, one proc.apply that starts <slug>.app with the new release's env.
    $types = deploy_types($world->agents, $server);
    expect($types)->toContain('proc.apply')->not->toContain('proc.restart')
        ->and(array_search('proc.apply', $types, true))->toBeGreaterThan(array_search('deploy.activate', $types, true));

    $app = collect($world->agents->last('proc.apply', $server)['payload']['programs'])->keyBy('name')->get("{$world->site->slug}.app");
    expect($app['env'])->toMatchArray([
        'KILN_RELEASE_ID' => strtoupper((string) $deployment->release_id),
        'KILN_DEPLOYMENT_ID' => strtoupper($deployment->id),
        'APP_KEY' => 'base64:secret',
        'PORT' => '3001',
    ]);

    $live = app(LiveReleases::class)->onServer($server)[$world->site->id];
    expect($live->releaseId)->toBe($deployment->release_id)
        ->and($live->deploymentId)->toBe($deployment->id)
        ->and(Release::query()->find($deployment->release_id)->environment)->toMatchArray(['APP_ENV' => 'production']);
});

it('moves the program env with every deploy and back on rollback', function () {
    $world = live_world(servers: 2);
    $first = live_deploy($world);
    $second = live_deploy($world);

    foreach ($world->servers as $server) {
        $app = collect($world->agents->last('proc.apply', $server->id)['payload']['programs'])->keyBy('name')->get("{$world->site->slug}.app");
        expect($app['env']['KILN_RELEASE_ID'])->toBe(strtoupper((string) $second->release_id));
    }

    $rollback = live_deploy($world, Trigger::Rollback, $first->release_id);
    expect($rollback->status)->toBe(DeploymentStatus::Succeeded);

    foreach ($world->servers as $server) {
        $app = collect($world->agents->last('proc.apply', $server->id)['payload']['programs'])->keyBy('name')->get("{$world->site->slug}.app");
        // The rolled-back-to release's own ids, like its .env.
        expect($app['env'])->toMatchArray(['KILN_RELEASE_ID' => strtoupper((string) $first->release_id), 'KILN_DEPLOYMENT_ID' => strtoupper($first->id)])
            ->and(app(LiveReleases::class)->onServer($server->id)[$world->site->id]->releaseId)->toBe($first->release_id);
    }
});

it('reverts the live release when a deployment fails after activation', function () {
    $world = live_world();
    $first = live_deploy($world);

    deploy_http(['http://203.0.113.1' => 500]);
    $failed = live_deploy($world);

    expect($failed->status)->toBe(DeploymentStatus::Failed)
        ->and($failed->rolled_back)->toBeTrue()
        ->and(app(LiveReleases::class)->onServer($world->servers[0]->id)[$world->site->id]->releaseId)->toBe($first->release_id);
});
