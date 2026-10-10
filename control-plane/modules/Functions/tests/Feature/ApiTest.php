<?php

use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Functions\Application\Actions\SaveSchedule;
use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionVersion;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Sites\Contracts\SiteDirectory;

require_once __DIR__.'/../../../Deployments/tests/Support/helpers.php';

const FN_API_V2 = "import { Hono } from 'hono'\nconst app = new Hono()\napp.get('/', (c) => c.text('from the CLI'))\nexport default app\n";

/**
 * A function site (one ready server, fn.v1 agent) and an API token for its organization.
 *
 * @param  list<string>  $abilities
 * @return array{0: DeployWorld, 1: CloudFunction, 2: string}
 */
function fn_api_world(array $abilities = ['*']): array
{
    $world = deploy_world(site: [
        'name' => 'Hooks', 'runtime' => 'function', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null,
        'repository' => null, 'source_connection_id' => null, 'branch' => null, 'deploy_script' => '', 'health_check_path' => null,
        'laravel' => [],
    ], actingAs: false);
    Agent::factory()->create(['server_id' => $world->servers[0]->id, 'organization_id' => $world->organization->id, 'facts' => ['features' => ['fn.v1']]]);
    $function = app(FunctionStore::class)->ensure(app(SiteDirectory::class)->find($world->site->id));
    $token = app(CreateApiToken::class)($world->user, $world->organization->id, 'cli', $abilities)->plainTextToken;

    return [$world, $function, $token];
}

it('lists functions and shows one by id or slug', function () {
    [$world, $function, $token] = fn_api_world();
    app(DeploymentTrigger::class)->deploy($world->site->id, null, $function->head()->hash);
    deploy_run_all($world->agents);

    $this->withToken($token)->getJson('/api/v1/functions')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $world->site->id)
        ->assertJsonPath('data.0.slug', $world->site->slug)
        ->assertJsonPath('data.0.runtime', 'bun')
        ->assertJsonPath('data.0.entrypoint', 'index.ts')
        ->assertJsonPath('data.0.live.number', 1);

    $this->withToken($token)->getJson("/api/v1/functions/{$world->site->slug}")->assertOk()
        ->assertJsonPath('data.site.id', $world->site->id)
        ->assertJsonPath('data.head.number', 1)
        ->assertJsonPath('data.live.number', 1)
        ->assertJsonPath('data.settings.max_instances', 5)
        ->assertJsonStructure(['data' => ['head' => ['id', 'hash', 'files'], 'schedules', 'url']]);
    $this->withToken($token)->getJson("/api/v1/functions/{$world->site->id}")->assertOk();
    $this->withToken($token)->getJson('/api/v1/functions/nope')->assertNotFound();
});

it('deploys code from the CLI, detects stale bases and lists versions', function () {
    [$world, $function, $token] = fn_api_world(['functions.view', 'functions.deploy', 'deployments.create']);
    $v1 = $function->head();

    $deployed = $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/deploy", ['files' => ['index.ts' => FN_API_V2], 'message' => 'From the CLI', 'base_version_id' => $v1->id])
        ->assertCreated()
        ->assertJsonPath('data.version.number', 2)
        ->assertJsonPath('data.created', true);

    expect(Deployment::query()->find($deployed->json('data.deployment_id'))->commit_message)->toBe('v2: From the CLI')
        ->and($world->agents->last('fn.release.apply')['payload']['files'][0]['content'])->toBe(FN_API_V2);

    // Based on v1 while v2 is newest: conflict with the newer code.
    $conflict = $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/deploy", ['files' => ['index.ts' => 'export default {}'], 'base_version_id' => $v1->id])
        ->assertStatus(409)
        ->assertJsonPath('head.number', 2);
    expect($conflict->json('head.files')['index.ts'])->toBe(FN_API_V2);

    // --force deploys over it; no base means "on top of the newest".
    $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/deploy", ['files' => ['index.ts' => 'export default { fetch: () => new Response("3") }'], 'base_version_id' => $v1->id, 'force' => true])
        ->assertCreated()->assertJsonPath('data.version.number', 3);
    $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/deploy", ['files' => ['index.ts' => FN_API_V2]])
        ->assertCreated()->assertJsonPath('data.version.number', 4);

    expect($this->withToken($token)->getJson("/api/v1/functions/{$world->site->slug}/versions")->assertOk()->json('data.*.number'))->toBe([4, 3, 2, 1]);
    $one = $this->withToken($token)->getJson("/api/v1/functions/{$world->site->slug}/versions/1")->assertOk();
    expect($one->json('data.files')['index.ts'])->toBe($v1->files['index.ts']);
});

it('rolls back to a version', function () {
    [$world, $function, $token] = fn_api_world();
    $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/deploy", ['files' => ['index.ts' => FN_API_V2]])->assertCreated();
    deploy_run_all($world->agents);

    $rollback = $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/versions/1/deploy")->assertCreated();
    deploy_run_all($world->agents);

    expect(Deployment::query()->find($rollback->json('data.deployment_id'))->commit)->toBe(FunctionVersion::query()->where('function_id', $function->id)->where('number', 1)->value('hash'))
        ->and(Deployment::query()->find($rollback->json('data.deployment_id'))->commit_message)->toStartWith('Roll back to v1');
});

it('needs deploy abilities to deploy, roll back or run', function () {
    [$world, , $token] = fn_api_world(['functions.view']);

    $this->withToken($token)->getJson("/api/v1/functions/{$world->site->slug}")->assertOk();
    $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/deploy", ['files' => ['index.ts' => FN_API_V2]])->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/versions/1/deploy")->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/schedules/hourly/run")->assertForbidden();

    // functions.deploy alone does not start deployments.
    [$world2, , $token2] = fn_api_world(['functions.view', 'functions.deploy']);
    app('auth')->forgetGuards();
    $this->withToken($token2)->postJson("/api/v1/functions/{$world2->site->slug}/deploy", ['files' => ['index.ts' => FN_API_V2]])->assertForbidden();
});

it('runs a schedule by name and reports the run', function () {
    [$world, $function, $token] = fn_api_world();
    $schedule = app(SaveSchedule::class)(app(SiteDirectory::class)->find($world->site->id), $function, null, ['name' => 'Nightly cleanup', 'expression' => '0 3 * * *']);

    $run = $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/schedules/nightly%20cleanup/run")
        ->assertStatus(202)
        ->assertJsonPath('data.schedule.key', $schedule->key())
        ->json('data.run_id');
    $dispatched = $world->agents->last('fn.run');
    expect($dispatched['payload'])->toBe(['site' => $world->site->slug, 'schedule' => $schedule->key(), 'name' => 'Nightly cleanup', 'cron' => '0 3 * * *', 'timeout_s' => 300]);

    $this->withToken($token)->getJson("/api/v1/functions/{$world->site->slug}/runs/{$run}")->assertOk()->assertJsonPath('data.finished', false);
    $world->agents->succeed($dispatched['handle'], ['exit_code' => 0, 'duration_ms' => 640]);
    $this->withToken($token)->getJson("/api/v1/functions/{$world->site->slug}/runs/{$run}")->assertOk()
        ->assertJsonPath('data.finished', true)
        ->assertJsonPath('data.exit_code', 0);

    // By key too; unknown schedules and runs are 404.
    $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/schedules/{$schedule->key()}/run")->assertStatus(202);
    $this->withToken($token)->postJson("/api/v1/functions/{$world->site->slug}/schedules/nope/run")->assertNotFound();
    $this->withToken($token)->getJson("/api/v1/functions/{$world->site->slug}/runs/01M3XXXXXXXXXXXXXXXXXXXXXX")->assertNotFound();
});

it('hides other organizations’ functions and non-function sites', function () {
    [$world] = fn_api_world();
    [, , $otherToken] = fn_api_world();

    $this->withToken($otherToken)->getJson("/api/v1/functions/{$world->site->id}")->assertNotFound();
    $this->withToken($otherToken)->getJson("/api/v1/functions/{$world->site->slug}")->assertNotFound();

    $site = deploy_world(actingAs: false);
    $token = app(CreateApiToken::class)($site->user, $site->organization->id, 'cli', ['*'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson("/api/v1/functions/{$site->site->slug}")->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/functions')->assertOk()->assertJsonCount(0, 'data');
});
