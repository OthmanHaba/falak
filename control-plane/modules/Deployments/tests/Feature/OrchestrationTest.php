<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Application\Jobs\ReconcileDeployments;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\ReleaseStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\DeploymentStep;
use Kiln\Deployments\Domain\Models\OutputLine;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Deployments\Domain\Models\SiteSettings;
use Kiln\Deployments\Events\DeploymentFailed;
use Kiln\Deployments\Events\DeploymentRolledBack;
use Kiln\Deployments\Events\DeploymentStarted;
use Kiln\Deployments\Events\DeploymentSucceeded;
use Kiln\Deployments\Events\ReleaseActivated;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Sites\Contracts\SiteDirectory;

require_once __DIR__.'/../Support/helpers.php';

function deploy(DeployWorld $world, Trigger $trigger = Trigger::Manual, ?string $releaseId = null, ?string $commit = null): Deployment
{
    return app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), $trigger, commit: $commit, releaseId: $releaseId);
}

function settings(DeployWorld $world, array $attributes): void
{
    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill($attributes)->save();
}

/**
 * Deploy to success end to end and return the deployment.
 */
function deploy_ok(DeployWorld $world): Deployment
{
    $deployment = deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    return $deployment->refresh();
}

it('deploys a single server through every phase in order', function () {
    Event::fake([DeploymentStarted::class, DeploymentSucceeded::class, ReleaseActivated::class, DeploymentFailed::class]);
    $world = deploy_world();

    $deployment = deploy($world);
    expect($deployment->status)->toBe(DeploymentStatus::Building)
        ->and($deployment->commit)->toBe(str_repeat('a', 40))
        ->and($deployment->commit_author)->toBe('Ada');
    $world->agents->assertNothingDispatched();

    $world->builds->succeed();
    deploy_run_all($world->agents);

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and(deploy_types($world->agents))->toBe([
            'deploy.hook',      // before_fetch
            'deploy.fetch',
            'deploy.prepare',
            'deploy.hook',      // migrate (leader)
            'deploy.activate',
            'deploy.hook',      // after_activate
            'proc.restart',
            'deploy.hook',      // after_restart
            'deploy.prune',
        ]);

    $hooks = array_map(fn ($c) => $c['payload'], $world->agents->dispatched('deploy.hook'));
    expect(array_column($hooks, 'name'))->toBe(['before_fetch', 'before_activate', 'after_activate', 'after_restart'])
        ->and($hooks[0]['cwd'])->toBe('site_root')
        ->and($hooks[1]['script'])->toContain('artisan migrate --force')->not->toContain('KILN_ACTIVATE')
        ->and($hooks[2]['script'])->toContain('echo "activated"')
        ->and($hooks[3]['script'])->toContain('echo "done"');

    $release = strtoupper((string) $deployment->release_id);
    $fetch = $world->agents->last('deploy.fetch')['payload'];
    expect($fetch['release_id'])->toBe($release)
        ->and($fetch['artifact']['url'])->toStartWith('https://')
        ->and($fetch['context'])->toMatchArray([
            'site_id' => strtoupper($world->site->id),
            'deployment_id' => strtoupper($deployment->id),
            'trigger' => 'manual',
            'php_binary' => 'php8.4',
        ]);

    $env = $world->agents->last('deploy.prepare')['payload']['env_file']['content'];
    expect($env)->toContain('APP_KEY=base64:secret')
        ->toContain('KILN_SITE_ID='.strtoupper($world->site->id))
        ->toContain('KILN_SERVER_ID='.strtoupper($world->servers[0]->id))
        ->toContain('KILN_DEPLOYMENT_ID='.strtoupper($deployment->id))
        ->toContain("KILN_RELEASE_ID={$release}")
        ->not->toContain('stale');

    $hookEnv = $hooks[1]['env'];
    expect($hookEnv)->toMatchArray([
        'KILN_RELEASE_ID' => $release,
        'KILN_RELEASE_DIR' => "/srv/kiln/sites/{$world->site->slug}/releases/{$release}",
        'KILN_DEPLOYMENT_ID' => strtoupper($deployment->id),
        'KILN_SITE_ID' => strtoupper($world->site->id),
        'KILN_SERVER_ID' => strtoupper($world->servers[0]->id),
        'KILN_COMMIT' => str_repeat('a', 40),
        'KILN_IS_LEADER' => '1',
        'KILN_TRIGGER' => 'manual',
        'APP_ENV' => 'production',
    ])->and($hookEnv)->not->toHaveKey('APP_KEY');

    expect($world->agents->last('deploy.activate')['payload']['reload'])->toBe([['kind' => 'frankenphp']])
        ->and($world->agents->last('proc.restart')['payload'])->toBe(['site' => $world->site->slug])
        ->and($world->agents->last('deploy.prune')['payload'])->toMatchArray(['keep' => 5, 'protect' => [$release]]);

    Http::assertSent(fn ($request) => $request->url() === 'http://203.0.113.1/up');

    $current = Release::current($world->site->id);
    expect($current?->id)->toBe($deployment->release_id)
        ->and($current->status)->toBe(ReleaseStatus::Active)
        ->and(array_column($world->annotations->calls, 'status'))->toBe(['started', 'succeeded'])
        ->and($world->annotations->calls[0]['deployment'])->toBe(strtoupper($deployment->id));

    Event::assertDispatched(DeploymentStarted::class);
    Event::assertDispatched(DeploymentSucceeded::class, fn ($e) => $e->deploymentId === $deployment->id);
    Event::assertDispatched(ReleaseActivated::class, fn ($e) => $e->releaseId === $deployment->release_id && $e->previousReleaseId === null);
    Event::assertNotDispatched(DeploymentFailed::class);

    expect(OutputLine::query()->where('deployment_id', $deployment->id)->where('phase', 'healthcheck')->exists())->toBeTrue();
});

it('fetches and prepares all servers in parallel, migrates on the leader only, then activates behind a barrier', function () {
    $world = deploy_world(servers: 3);
    [$leader, $second, $third] = $world->serverIds();
    deploy($world);
    $world->builds->succeed();

    // before_fetch hooks run everywhere at once.
    expect(deploy_pending($world->agents, 'deploy.hook'))->toHaveCount(3);
    deploy_complete($world->agents, 'deploy.hook');
    expect(deploy_pending($world->agents, 'deploy.fetch'))->toHaveCount(3);

    deploy_complete($world->agents, 'deploy.fetch');
    deploy_complete($world->agents, 'deploy.prepare', $leader);
    deploy_complete($world->agents, 'deploy.prepare', $second);

    // Members run the pre-activation section (not the leader); no migrate before every server is prepared.
    expect(array_map(fn ($c) => $c['handle']->serverId, deploy_pending($world->agents, 'deploy.hook')))->toBe([$second])
        ->and(deploy_pending($world->agents, 'deploy.hook')[0]['payload']['env']['KILN_IS_LEADER'])->toBe('0');

    deploy_complete($world->agents, 'deploy.prepare', $third);
    $migrate = array_values(array_filter(deploy_pending($world->agents, 'deploy.hook'), fn ($c) => $c['handle']->serverId === $leader));
    expect($migrate)->toHaveCount(1)
        ->and($migrate[0]['payload']['env']['KILN_IS_LEADER'])->toBe('1')
        ->and(DeploymentStep::query()->where('phase', 'migrate')->pluck('server_id')->all())->toBe([$leader]);

    // Barrier: nothing activates until the leader migrated and every member finished its hook.
    deploy_complete($world->agents, 'deploy.hook', $leader);
    deploy_complete($world->agents, 'deploy.hook', $second);
    expect($world->agents->dispatched('deploy.activate'))->toBe([]);

    deploy_complete($world->agents, 'deploy.hook', $third);
    expect(array_map(fn ($c) => $c['handle']->serverId, deploy_pending($world->agents, 'deploy.activate')))->toEqualCanonicalizing([$leader, $second, $third]);

    deploy_run_all($world->agents);
    expect(Deployment::query()->sole()->status)->toBe(DeploymentStatus::Succeeded);
});

dataset('failing phases', [
    'fetch' => ['deploy.fetch', 0],
    'prepare' => ['deploy.prepare', 0],
    'migrate' => ['migrate', 0],
    'activate (one of two)' => ['deploy.activate', 1],
    'restart' => ['proc.restart', 2],
    'healthcheck' => ['healthcheck', 2],
]);

it('rolls back exactly the servers that switched releases when a phase fails', function (string $failing, int $expectedRollbacks) {
    Event::fake([DeploymentFailed::class, DeploymentRolledBack::class, DeploymentSucceeded::class]);
    $world = deploy_world(servers: 2);
    [$leader, $member] = $world->serverIds();
    $first = deploy_ok($world);

    $deployment = deploy($world);
    $world->builds->succeed();

    if ($failing === 'healthcheck') {
        deploy_http(['http://203.0.113.2' => 500]);
    }

    for ($i = 0; $i < 60; $i++) {
        $pending = deploy_pending($world->agents);

        if ($pending === []) {
            break;
        }

        foreach ($pending as $command) {
            $isTarget = match ($failing) {
                'migrate' => $command['handle']->type === 'deploy.hook' && $command['payload']['name'] === 'before_activate' && $command['handle']->serverId === $leader,
                'deploy.activate' => $command['handle']->type === 'deploy.activate' && $command['handle']->serverId === $member,
                'proc.restart' => $command['handle']->type === 'proc.restart' && $command['handle']->serverId === $member,
                'healthcheck' => false,
                default => $command['handle']->type === $failing && $command['handle']->serverId === $member,
            };

            $isTarget
                ? $world->agents->fail($command['handle'], 'exploded', 1)
                : $world->agents->succeed($command['handle'], deploy_result($command));
        }
    }

    $deployment->refresh();
    $rollbacks = $world->agents->dispatched('deploy.rollback');

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->rolled_back)->toBe($expectedRollbacks > 0)
        ->and($rollbacks)->toHaveCount($expectedRollbacks);

    foreach ($rollbacks as $rollback) {
        expect($rollback['payload']['release_id'])->toBe(strtoupper($first->release_id))
            ->and($rollback['payload']['context']['trigger'])->toBe('rollback');
    }

    if ($failing === 'deploy.activate') {
        expect($rollbacks[0]['handle']->serverId)->toBe($leader);
    }

    // The previous release stays current; the failed one is marked failed.
    expect(Release::current($world->site->id)?->id)->toBe($first->release_id)
        ->and(Release::query()->find($deployment->release_id)->status)->toBe(ReleaseStatus::Failed)
        ->and(array_column($world->annotations->calls, 'status'))->toContain($expectedRollbacks > 0 ? 'rolled_back' : 'failed');

    Event::assertDispatched(DeploymentFailed::class, fn ($e) => $e->deploymentId === $deployment->id && $e->toAlert()->type === 'deployments.failed');
    $expectedRollbacks > 0
        ? Event::assertDispatched(DeploymentRolledBack::class, fn ($e) => $e->automatic && count($e->serverIds) === $expectedRollbacks)
        : Event::assertNotDispatched(DeploymentRolledBack::class);
})->with('failing phases');

it('never rolls back on a first deployment without an earlier release', function () {
    $world = deploy_world();
    deploy_http(['http' => 503]);
    $deployment = deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($world->agents->dispatched('deploy.rollback'))->toBe([])
        ->and($deployment->error)->toContain('Health check on web-1 failed');
});

it('aborts a canary whose health check fails before touching the other servers', function () {
    $world = deploy_world(servers: 3);
    $first = deploy_ok($world);
    settings($world, ['strategy' => 'canary', 'health_retries' => 2]);
    deploy_http(['http://203.0.113.1' => 500]);

    $deployment = deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    $activations = array_slice($world->agents->dispatched('deploy.activate'), 3);
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(array_map(fn ($c) => $c['handle']->serverId, $activations))->toBe([$world->servers[0]->id])
        ->and(array_map(fn ($c) => $c['handle']->serverId, $world->agents->dispatched('deploy.rollback')))->toBe([$world->servers[0]->id])
        ->and($world->agents->last('deploy.rollback')['payload']['release_id'])->toBe(strtoupper($first->release_id));

    Http::assertSentCount(3 + 2); // first deploy (3 servers) + two canary attempts
});

it('activates rolling batches one after another, each gated by its health checks', function () {
    $world = deploy_world(servers: 4);
    settings($world, ['strategy' => 'rolling', 'batch_size' => 2]);
    deploy($world);
    $world->builds->succeed();

    for ($i = 0; $i < 20 && deploy_complete($world->agents, 'deploy.hook') + deploy_complete($world->agents, 'deploy.fetch') + deploy_complete($world->agents, 'deploy.prepare') > 0; $i++) {
    }

    $ids = $world->serverIds();
    expect(array_map(fn ($c) => $c['handle']->serverId, $world->agents->dispatched('deploy.activate')))->toEqualCanonicalizing([$ids[0], $ids[1]]);

    // Finish batch 1 up to (not including) the health check: batch 2 must still wait.
    deploy_complete($world->agents, 'deploy.activate');
    expect(count($world->agents->dispatched('deploy.activate')))->toBe(2);

    deploy_run_all($world->agents);

    $order = array_map(fn ($c) => $c['handle']->serverId, $world->agents->dispatched('deploy.activate'));
    expect($order)->toHaveCount(4)
        ->and(array_slice($order, 2))->toEqualCanonicalizing([$ids[2], $ids[3]])
        ->and(Deployment::query()->sole()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(DeploymentStep::query()->where('key', 'like', 'activate:%')->orderBy('position')->pluck('batch')->all())->toBe([0, 0, 1, 1]);
});

it('activates in-place servers without waiting for the others', function () {
    $world = deploy_world(servers: 2);
    settings($world, ['strategy' => 'in-place']);
    deploy($world);
    $world->builds->succeed();
    [$leader, $member] = $world->serverIds();

    deploy_complete($world->agents, 'deploy.hook');
    deploy_complete($world->agents, 'deploy.fetch');
    deploy_complete($world->agents, 'deploy.prepare');
    deploy_complete($world->agents, 'deploy.hook', $leader); // migrate

    // The leader is ready and activates although the member's pre-activation hook still runs.
    expect(array_map(fn ($c) => $c['handle']->serverId, $world->agents->dispatched('deploy.activate')))->toBe([$leader])
        ->and(deploy_pending($world->agents, 'deploy.hook', $member))->toHaveCount(1);
});

it('queues deployments per site and starts the next one when the active one finishes', function () {
    $world = deploy_world();
    $one = deploy($world);
    $two = deploy($world);
    $three = deploy($world);

    expect($one->status)->toBe(DeploymentStatus::Building)
        ->and($two->status)->toBe(DeploymentStatus::Queued)
        ->and($three->status)->toBe(DeploymentStatus::Queued)
        ->and($world->builds->builds)->toHaveCount(1);

    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($one->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($two->refresh()->status)->toBe(DeploymentStatus::Building)
        ->and($three->refresh()->status)->toBe(DeploymentStatus::Queued)
        ->and($world->builds->builds)->toHaveCount(2);
});

it('ignores re-delivered command events', function () {
    $world = deploy_world();
    $deployment = deploy($world);
    $world->builds->succeed();
    deploy_complete($world->agents, 'deploy.hook');
    $fetch = $world->agents->last('deploy.fetch');
    $world->agents->succeed($fetch['handle'], deploy_result($fetch));
    $before = count($world->agents->commands);

    // Same CommandFinished again (at-least-once delivery), and a late failure for it.
    CommandFinished::dispatch($fetch['handle']->id, $world->organization->id, $fetch['handle']->serverId, 'deploy.fetch', $fetch['handle']->idempotencyKey, 0, deploy_result($fetch));
    $world->agents->fail($fetch['handle']);

    expect(count($world->agents->commands))->toBe($before)
        ->and(DeploymentStep::query()->where('key', 'like', 'fetch:%')->sole()->status->value)->toBe('succeeded')
        ->and($deployment->refresh()->status)->toBe(DeploymentStatus::Deploying);

    deploy_run_all($world->agents);
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($world->agents->dispatched('deploy.fetch'))->toHaveCount(1);
});

it('fails the deployment when a deploy script section exits non-zero', function () {
    $world = deploy_world();
    $deployment = deploy($world);
    $world->builds->succeed();
    $hook = $world->agents->last('deploy.hook');
    $world->agents->succeed($hook['handle'], ['exit_code' => 3]);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('before_fetch')
        ->and($world->agents->dispatched('deploy.fetch'))->toBe([]);
});

it('fails without touching servers when the build fails', function () {
    Event::fake([DeploymentFailed::class]);
    $world = deploy_world();
    $deployment = deploy($world);
    $world->builds->fail(error: 'composer install failed');

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('composer install failed')
        ->and(Release::query()->find($deployment->release_id)->status)->toBe(ReleaseStatus::Failed);
    $world->agents->assertNothingDispatched();
    Event::assertDispatched(DeploymentFailed::class, fn ($e) => $e->phase === 'build');
});

it('cancels queued deployments and deployments that are still building', function () {
    Event::fake([DeploymentFailed::class]);
    $world = deploy_world();
    $building = deploy($world);
    $queued = deploy($world);

    $this->post("/sites/{$world->site->id}/deployments/{$queued->id}/cancel")->assertRedirect();
    expect($queued->refresh()->status)->toBe(DeploymentStatus::Cancelled);

    $this->post("/sites/{$world->site->id}/deployments/{$building->id}/cancel")->assertRedirect();
    expect($building->refresh()->status)->toBe(DeploymentStatus::Cancelled);
    $world->agents->assertNothingDispatched();
    Event::assertNotDispatched(DeploymentFailed::class);
});

it('rolls back to a retained release on all servers', function () {
    Event::fake([DeploymentRolledBack::class, ReleaseActivated::class]);
    $world = deploy_world(servers: 2);
    $first = deploy_ok($world);
    $second = deploy_ok($world);

    expect(Release::query()->find($first->release_id)->status)->toBe(ReleaseStatus::Inactive);

    $rollback = deploy($world, Trigger::Rollback, $first->release_id);
    deploy_run_all($world->agents);

    $switches = $world->agents->dispatched('deploy.rollback');
    expect($rollback->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($rollback->release_id)->toBe($first->release_id)
        ->and(array_map(fn ($c) => $c['handle']->serverId, $switches))->toEqualCanonicalizing($world->serverIds())
        ->and($switches[0]['payload']['release_id'])->toBe(strtoupper($first->release_id))
        ->and(Release::current($world->site->id)->id)->toBe($first->release_id)
        ->and(Release::query()->find($second->release_id)->status)->toBe(ReleaseStatus::Inactive)
        ->and(count($world->builds->builds))->toBe(2);

    Event::assertDispatched(DeploymentRolledBack::class, fn ($e) => ! $e->automatic && $e->toReleaseId === $first->release_id);
});

it('keeps N releases and marks older ones pruned', function () {
    $world = deploy_world();
    settings($world, ['keep_releases' => 2]);
    $deployments = [deploy_ok($world), deploy_ok($world), deploy_ok($world)];

    expect(Release::query()->find($deployments[0]->release_id)->status)->toBe(ReleaseStatus::Pruned)
        ->and(Release::query()->find($deployments[1]->release_id)->status)->toBe(ReleaseStatus::Inactive)
        ->and(Release::query()->find($deployments[2]->release_id)->status)->toBe(ReleaseStatus::Active)
        ->and($world->agents->last('deploy.prune')['payload']['keep'])->toBe(2);

    $this->postJson("/sites/{$world->site->id}/releases/{$deployments[0]->release_id}/rollback")->assertUnprocessable();
});

it('swaps containers blue/green and records the new upstream with Edge', function () {
    $world = deploy_world(servers: 2, site: ['runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'app_port' => 3100, 'deploy_script' => '$KILN_FETCH']);
    $deployment = deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    $swap = $world->agents->last('deploy.container.swap')['payload'];
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(deploy_types($world->agents))->toBe(['deploy.container.swap', 'deploy.container.swap'])
        ->and($swap['image'])->toStartWith('registry.kiln.local/kiln/app@sha256:')
        ->and($swap['registry_auth']['username'])->toBe('kiln')
        ->and($swap['ports'])->toBe(['blue' => 3100, 'green' => 4100])
        ->and($swap['edge_route_id'])->toBe("site-{$world->site->id}")
        ->and($swap['env']['KILN_RELEASE_ID'])->toBe(strtoupper($deployment->release_id))
        ->and($world->edge->upstreams)->toHaveCount(2)
        ->and($world->edge->upstreams[0])->toMatchArray(['site' => $world->site->id, 'upstream' => '127.0.0.1:4100'])
        ->and(Release::current($world->site->id)->image)->toStartWith('registry.kiln.local/');
});

it('resumes a deployment whose command events were lost', function () {
    $world = deploy_world();
    $deployment = deploy($world);
    $world->builds->succeed();

    // The agent finished the hook but the event never reached the listener.
    $hook = $world->agents->last('deploy.hook');
    $world->agents->commands[$hook['handle']->id]['status'] = \Kiln\Fleet\Contracts\CommandStatus::Succeeded;
    $world->agents->commands[$hook['handle']->id]['result'] = ['exit_code' => 0];
    DeploymentStep::query()->where('command_id', $hook['handle']->id)->update(['updated_at' => now()->subMinutes(10)]);

    (new ReconcileDeployments)->handle(app(\Kiln\Deployments\Application\Orchestration\Orchestrator::class), app(\Kiln\Deployments\Application\Orchestration\DeploymentQueue::class));

    expect($world->agents->dispatched('deploy.fetch'))->toHaveCount(1)
        ->and($deployment->refresh()->status)->toBe(DeploymentStatus::Deploying);
});

it('records agent output per server and phase', function () {
    $world = deploy_world();
    $deployment = deploy($world);
    $world->builds->succeed();
    $hook = $world->agents->last('deploy.hook');
    $world->agents->emit($hook['handle'], ["before fetch\n", "second\n"]);
    // At-least-once ingestion: the same seq again must not duplicate the line.
    \Kiln\Fleet\Events\CommandOutputReceived::dispatch($hook['handle']->id, $hook['handle']->serverId, 'running', [
        ['seq' => 1, 'kind' => 'output', 'stream' => 'stdout', 'data' => "before fetch\n", 'progress' => null, 'at' => now()->toIso8601ZuluString()],
    ]);

    $lines = OutputLine::query()->where('deployment_id', $deployment->id)->whereNotNull('source_seq')->orderBy('id')->get();
    expect($lines)->toHaveCount(2)
        ->and($lines[0]->server_name)->toBe('web-1')
        ->and($lines[0]->phase)->toBe('fetch');
});
