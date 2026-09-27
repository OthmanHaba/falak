<?php

use Illuminate\Support\Facades\Event;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Application\Orchestration\StepPayloads;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\ReleaseStatus;
use Kiln\Deployments\Domain\Enums\Strategy;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Deployments\Domain\Models\SiteSettings;
use Kiln\Deployments\Events\DeploymentFailed;
use Kiln\Deployments\Events\DeploymentRolledBack;
use Kiln\Sites\Contracts\ComposeSites;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Domain\Models\ComposeVersion;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../Support/helpers.php';

const COMPOSE_REPO_FILE = <<<'YAML'
services:
  app:
    build: .
    ports: ["8080:8080"]
    environment:
      REDIS_URL: redis://redis:6379
      SECRET: ${APP_KEY}
    labels:
      kiln.deploy.leader_command: "node migrate.js --force"
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://localhost:8080/health"]
  redis:
    image: redis:7.4.1-alpine
    volumes: [redis-data:/data]
volumes:
  redis-data:
YAML;

function compose_world(int $servers = 1, array $site = []): DeployWorld
{
    $world = deploy_world(servers: $servers, site: [
        'runtime' => 'compose', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null,
        'compose_source' => 'repo', 'compose_file' => null, 'deploy_script' => '',
        'public_services' => [['service' => 'app', 'port' => 8080, 'domain' => null, 'host_port' => 3000]], 'app_port' => 3000,
        'test_domain_enabled' => true,
        ...$site,
    ]);
    config(['sites.test_domain' => 'kiln.test', 'builds.registry.username' => 'kiln', 'builds.registry.password' => 'secret']);
    $world->builds->composeContent = COMPOSE_REPO_FILE;

    return $world;
}

function compose_deploy(DeployWorld $world, Trigger $trigger = Trigger::Manual, ?string $releaseId = null): Deployment
{
    return app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), $trigger, releaseId: $releaseId);
}

it('deploys a repo compose site: build → pull everywhere → leader command → up --wait → health check', function () {
    $world = compose_world(servers: 2);
    $deployment = compose_deploy($world);

    expect($deployment->refresh()->strategy)->toBe(Strategy::Compose)
        ->and($deployment->status)->toBe(DeploymentStatus::Building);

    $world->builds->succeed();
    deploy_complete($world->agents, 'docker.compose.pull');

    // Leader command only on the leader, before any activation.
    $leader = deploy_pending($world->agents, 'system.exec');
    expect($leader)->toHaveCount(1)
        ->and($leader[0]['handle']->serverId)->toBe($world->servers[0]->id)
        ->and($leader[0]['payload']['script'])->toContain("run --rm -T --no-deps 'app' 'node' 'migrate.js' '--force'")
        ->and(deploy_pending($world->agents, 'docker.compose.up'))->toBe([]);

    deploy_run_all($world->agents);

    $up = $world->agents->last('docker.compose.up')['payload'];
    $rendered = Yaml::parse($up['files'][0]['content']);
    $release = Release::query()->find($deployment->release_id);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(deploy_types($world->agents, $world->servers[1]->id))->toBe(['docker.compose.pull', 'docker.compose.up', 'deploy.prune'])
        ->and($up['project'])->toBe($world->site->slug)
        ->and($up['directory'])->toBe("/srv/kiln/sites/{$world->site->slug}/releases/".strtoupper($deployment->release_id))
        ->and($up['wait'])->toBeTrue()
        ->and($up['wait_timeout_s'])->toBe(300)
        ->and($up['project_env_file'])->toBe('.env')
        ->and($up['registry_auth']['username'])->toBe('kiln')
        ->and($up['env']['APP_KEY'])->toBe('base64:secret')
        ->and($up['env']['KILN_SERVER_ID'])->toBe(strtoupper($world->servers[1]->id))
        ->and($up['env']['KILN_RELEASE_ID'])->toBe(strtoupper($deployment->release_id))
        ->and($up['files'][1]['content'])->toContain('KILN_SITE_ID='.strtoupper($world->site->id))
        ->and($rendered['services']['app']['image'])->toStartWith('registry.kiln.local/kiln/shop/app@sha256:')
        ->and($rendered['services']['app'])->not->toHaveKey('build')
        ->and($rendered['services']['app']['ports'])->toBe(['127.0.0.1:3000:8080'])
        ->and($rendered['services']['app']['labels']['kiln.release'])->toBe(strtoupper($deployment->release_id))
        ->and($release->status)->toBe(ReleaseStatus::Active)
        // pulled images are pinned to what the server resolved
        ->and(Yaml::parse($release->compose['yaml'])['services']['redis']['image'])->toBe('redis:7.4.1-alpine@sha256:'.str_repeat('2', 64));

    // Health check through the edge on the public service's test domain.
    expect(collect($GLOBALS['deploy_http_requests'])->pluck('url')->all())->toContain('https://'.$world->site->slug.'.kiln.test/up');

    // Services tab state recorded from the up result.
    expect(app(ComposeSites::class)->status($world->site->id))->toHaveCount(4);
});

it('deploys inline compose files without a build and skips the leader step when there is no leader command', function () {
    $world = compose_world(site: ['compose_source' => 'inline', 'repository' => null, 'source_connection_id' => null]);
    ComposeVersion::query()->create(['site_id' => $world->site->id, 'version' => 1, 'content' => "services:\n  app:\n    image: nginx:1.27\n  redis:\n    image: redis:7\n", 'created_at' => now()]);

    $deployment = compose_deploy($world);
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($world->builds->builds)->toBe([])
        ->and(deploy_types($world->agents))->toBe(['docker.compose.pull', 'docker.compose.up', 'deploy.prune'])
        ->and($world->agents->last('docker.compose.up')['payload'])->not->toHaveKey('registry_auth')
        ->and(Release::query()->find($deployment->release_id)->compose['version'])->toBe(1);
});

it('rolls a failed compose release back with the previous release files', function () {
    Event::fake([DeploymentFailed::class, DeploymentRolledBack::class]);
    $world = compose_world(servers: 2);
    $first = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);
    expect($first->refresh()->status)->toBe(DeploymentStatus::Succeeded);
    $firstYaml = Release::query()->find($first->release_id)->compose['yaml'];

    $second = compose_deploy($world);
    $world->builds->succeed();
    deploy_complete($world->agents, 'docker.compose.pull');
    deploy_complete($world->agents, 'system.exec');

    // app-1 comes up, app-2's containers never get healthy.
    $ups = deploy_pending($world->agents, 'docker.compose.up');
    expect($ups)->toHaveCount(2);
    $world->agents->succeed($ups[0]['handle'], deploy_result($ups[0]));
    $world->agents->fail($ups[1]['handle'], 'docker compose up exited 1', 1, result: ['exit_code' => 1, 'services' => [
        ['service' => 'app', 'container_id' => 'c', 'state' => 'running', 'health' => 'unhealthy', 'image' => 'app', 'restarts' => 3],
    ]]);

    // Both servers go back: app-1 switched, and app-2's failed `up` already replaced its containers.
    $reverts = deploy_pending($world->agents, 'docker.compose.up');
    expect($reverts)->toHaveCount(2)
        ->and(array_map(fn ($c) => $c['handle']->serverId, $reverts))->toEqualCanonicalizing($world->serverIds())
        ->and($reverts[0]['payload']['directory'])->toEndWith('/releases/'.strtoupper($first->release_id))
        ->and($reverts[0]['payload']['files'][0]['content'])->toBe($firstYaml);

    deploy_run_all($world->agents);

    expect($second->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($second->rolled_back)->toBeTrue()
        ->and($second->error)->toContain('docker compose up exited 1')
        ->and(Release::current($world->site->id)->id)->toBe($first->release_id)
        ->and(Release::query()->find($second->release_id)->status)->toBe(ReleaseStatus::Failed);

    Event::assertDispatched(DeploymentRolledBack::class, fn ($e) => $e->automatic);
});

it('rolls back manually to a retained compose release', function () {
    $world = compose_world();
    $first = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);
    compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    $rollback = compose_deploy($world, Trigger::Rollback, $first->release_id);
    deploy_run_all($world->agents);

    $up = $world->agents->last('docker.compose.up')['payload'];
    expect($rollback->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($up['directory'])->toEndWith('/releases/'.strtoupper($first->release_id))
        ->and(Release::current($world->site->id)->id)->toBe($first->release_id);
});

it('fails with a clear error when the compose file cannot be rendered', function () {
    $world = compose_world();
    $world->builds->composeContent = "services:\n  web:\n    image: nginx:1\n";
    $deployment = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('The public service app is not in the compose file.')
        ->and($world->agents->dispatched('docker.compose.up'))->toBe([]);
});

it('checks every public service through the edge', function () {
    $world = compose_world(site: ['public_services' => [
        ['service' => 'app', 'port' => 8080, 'domain' => null, 'host_port' => 3000],
        ['service' => 'redis', 'port' => 6379, 'domain' => 'cache.example.com', 'host_port' => 3001],
    ]]);
    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill(['health_path' => '/health'])->save();
    deploy_http(['https://cache.example.com' => 503]);

    $deployment = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('[redis] GET https://cache.example.com/')
        ->and(collect($GLOBALS['deploy_http_requests'])->pluck('url')->all())->toContain('https://'.$world->site->slug.'.kiln.test/health');
});

it('accepts a redirect from the primary public service (apps that redirect to a login page)', function () {
    $world = compose_world();
    deploy_http(['https://'.$world->site->slug.'.kiln.test' => 302]);
    $deployment = compose_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded);
});

it('writes a compose .env that compose reads literally', function () {
    expect(StepPayloads::composeDotenv(['A' => 'plain', 'B' => 'has space $HOME', 'C' => "it's\nmultiline"]))
        ->toBe("A=plain\nB='has space \$HOME'\nC=\"it's\\nmultiline\"\n");
});
