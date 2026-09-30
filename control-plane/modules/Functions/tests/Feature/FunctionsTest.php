<?php

use Kiln\Deployments\Contracts\DeploymentTrigger;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Functions\Application\Code;
use Kiln\Functions\Application\FunctionStore;
use Kiln\Functions\Domain\Models\CloudFunction;
use Kiln\Functions\Domain\Models\FunctionDraft;
use Kiln\Functions\Domain\Models\FunctionVersion;
use Kiln\Identity\Contracts\Role;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteDomains;
use Kiln\Sites\Contracts\SiteFactory;
use Kiln\Sites\Domain\Models\Site;

require_once __DIR__.'/../../../Deployments/tests/Support/helpers.php';
require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';

const FN_V2 = "import { Hono } from 'hono'\nconst app = new Hono()\napp.get('/', (c) => c.text('v2'))\nexport default app\n";

/**
 * A function site on one ready server (agent with the fn.v1 feature) and its function row (Hello starter as v1).
 */
function fn_world(Role $role = Role::Owner): array
{
    $world = deploy_world(site: [
        'name' => 'Hooks', 'runtime' => 'function', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null,
        'repository' => null, 'source_connection_id' => null, 'branch' => null, 'deploy_script' => '', 'health_check_path' => null,
        'laravel' => [], 'shared_paths' => [],
    ], role: $role);
    fn_agent($world->servers[0]->id, $world->organization->id);
    $function = app(FunctionStore::class)->ensure(app(SiteDirectory::class)->find($world->site->id));

    return [$world, $function];
}

function fn_agent(string $serverId, string $organizationId, array $features = ['fn.v1']): void
{
    Agent::factory()->create(['server_id' => $serverId, 'organization_id' => $organizationId, 'facts' => ['features' => $features, 'memory_bytes' => 4 * 1024 ** 3]]);
}

function fn_url(Site $site, string $path = ''): string
{
    return "/sites/{$site->id}/function".$path;
}

it('creates a function from the canvas and deploys its starter through the gateway', function () {
    $world = deploy_world(site: ['runtime' => 'static', 'framework' => 'static', 'php_version' => null]);
    fn_agent($world->servers[0]->id, $world->organization->id);
    $environment = projects_default_env($world->organization);

    $response = $this->postJson("/projects/{$environment->project_id}/{$environment->slug}/functions", [
        'name' => 'Webhooks',
        'server_id' => $world->servers[0]->id,
        'starter' => 'webhook',
    ])->assertCreated();

    $site = Site::query()->findOrFail($response->json('data.site_id'));
    $function = CloudFunction::query()->where('site_id', $site->id)->firstOrFail();
    $version = $function->head();
    $apply = $world->agents->last('fn.release.apply');

    expect($site->runtime->value)->toBe('function')
        ->and($site->app_port)->toBeNull()
        ->and($site->health_check_path)->toBeNull()
        // no domain chosen: the organization's default (a generated name here)
        ->and(app(SiteDomains::class)->primaryDomains([$site->id])[$site->id] ?? null)->toBe("{$site->slug}.203-0-113-1.sslip.io")
        ->and($version->number)->toBe(1)
        ->and($version->files['index.ts'])->toContain('X-Hub-Signature-256')
        ->and($response->json('data.deployment_id'))->not->toBeNull()
        ->and($apply['handle']->serverId)->toBe($world->servers[0]->id)
        ->and($apply['payload']['site'])->toBe($site->slug)
        ->and($apply['payload']['entrypoint'])->toBe('index.ts')
        ->and($apply['payload']['files'])->toBe([['path' => 'index.ts', 'content' => $version->files['index.ts']]])
        ->and($apply['payload']['image'])->toContain('/kiln-fn-bun:')
        ->and($apply['payload']['scaling'])->toBe(['min_instances' => 0, 'max_instances' => 5, 'concurrency' => 50, 'idle_timeout_s' => 300])
        ->and($apply['payload']['limits']['memory_bytes'])->toBe(256 * 1024 * 1024)
        ->and($apply['payload']['env'])->not->toHaveKey('PORT')
        ->and($apply['payload']['env']['KILN_SITE_ID'])->toBe(strtoupper($site->id));

    $world->agents->succeed($apply['handle'], ['release' => $apply['payload']['release'], 'installed' => true, 'boot_ms' => 180]);
    $deployment = Deployment::query()->findOrFail($response->json('data.deployment_id'));

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->commit)->toBe($version->hash)
        ->and($deployment->commit_message)->toBe('v1: Created from the Webhook receiver starter')
        ->and(Release::query()->find($deployment->release_id)->commit)->toBe($version->hash)
        // functions have no health check step and no deploy.prune (the agent prunes releases itself)
        ->and(deploy_types($world->agents))->not->toContain('deploy.prune');
});

it('refuses servers whose agent has no function gateway', function () {
    $world = deploy_world(site: ['runtime' => 'static', 'framework' => 'static', 'php_version' => null]);
    fn_agent($world->servers[0]->id, $world->organization->id, features: []);
    $environment = projects_default_env($world->organization);

    $this->postJson("/projects/{$environment->project_id}/{$environment->slug}/functions", ['name' => 'Old', 'server_id' => $world->servers[0]->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['server_ids' => 'too old for functions']);
});

it('versions every deploy, detects stale editors and skips unchanged code', function () {
    [$world, $function] = fn_world();
    $v1 = $function->head();

    $this->getJson(fn_url($world->site))->assertOk()
        ->assertJsonPath('data.head.number', 1)
        ->assertJsonPath('data.runtime.label', 'Bun + Hono')
        ->assertJsonPath('data.can.deploy', true);

    // Draft autosave, then deploy removes it.
    $this->putJson(fn_url($world->site, '/draft'), ['files' => ['index.ts' => FN_V2], 'base_version_id' => $v1->id])->assertOk();
    expect(FunctionDraft::query()->count())->toBe(1);

    $deployed = $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => FN_V2], 'message' => 'Say v2', 'base_version_id' => $v1->id])
        ->assertCreated()
        ->assertJsonPath('data.version.number', 2)
        ->assertJsonPath('data.version.author', $world->user->name);

    expect(FunctionDraft::query()->count())->toBe(0)
        ->and(Deployment::query()->find($deployed->json('data.deployment_id'))->commit_message)->toBe('v2: Say v2')
        ->and($world->agents->last('fn.release.apply')['payload']['files'][0]['content'])->toBe(FN_V2);

    // A teammate still editing from v1 gets the conflict with the newer code.
    $conflict = $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => 'export default { fetch: () => new Response("mine") }'], 'base_version_id' => $v1->id])
        ->assertStatus(409)
        ->assertJsonPath('head.number', 2);
    expect($conflict->json('head.files')['index.ts'])->toBe(FN_V2);

    // Same code as the newest version: no new version.
    $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => FN_V2], 'base_version_id' => $deployed->json('data.version.id')])
        ->assertOk()
        ->assertJsonPath('data.created', false);

    expect(FunctionVersion::query()->where('function_id', $function->id)->count())->toBe(2)
        ->and($this->getJson(fn_url($world->site, '/versions'))->json('data.*.number'))->toBe([2, 1]);
});

it('validates the code', function (array $files, string $error) {
    [$world, $function] = fn_world();

    $this->postJson(fn_url($world->site, '/deploy'), ['files' => $files, 'base_version_id' => $function->head()->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['files' => $error]);
})->with([
    'no entrypoint' => [['main.ts' => 'x'], 'entrypoint index.ts is missing'],
    'escaping path' => [['index.ts' => 'x', '../etc/passwd' => 'x'], 'not a valid file path'],
    'dot file' => [['index.ts' => 'x', '.env' => 'x'], 'not a valid file path'],
]);

it('rolls back to an earlier version with its exact code', function () {
    [$world, $function] = fn_world();
    $v1 = $function->head();
    $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => FN_V2], 'base_version_id' => $v1->id])->assertCreated();
    deploy_run_all($world->agents);

    $rollback = $this->postJson(fn_url($world->site, '/versions/1/deploy'))->assertCreated();
    $apply = $world->agents->last('fn.release.apply')['payload'];
    deploy_run_all($world->agents);
    $deployment = Deployment::query()->find($rollback->json('data.deployment_id'));

    expect($apply['files'][0]['content'])->toBe($v1->files['index.ts'])
        ->and($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->commit)->toBe($v1->hash)
        ->and($deployment->commit_message)->toStartWith('Roll back to v1')
        ->and(Release::current($world->site->id)->commit)->toBe($v1->hash)
        ->and($this->getJson(fn_url($world->site))->json('data.live.number'))->toBe(1);
});

it('redeploys the live code when scaling changes', function () {
    [$world, $function] = fn_world();
    app(DeploymentTrigger::class)->deploy($world->site->id, null, $function->head()->hash);
    deploy_run_all($world->agents);

    $this->putJson(fn_url($world->site, '/settings'), ['min_instances' => 3, 'max_instances' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('max_instances');
    $this->putJson(fn_url($world->site, '/settings'), ['max_instances' => 99])
        ->assertUnprocessable()->assertJsonValidationErrors('max_instances');

    $response = $this->putJson(fn_url($world->site, '/settings'), ['min_instances' => 1, 'max_instances' => 10, 'memory_mb' => 512, 'idle_timeout_s' => 60])->assertOk();

    expect($response->json('data.deployment_id'))->not->toBeNull()
        ->and($world->agents->last('fn.release.apply')['payload']['scaling'])->toBe(['min_instances' => 1, 'max_instances' => 10, 'concurrency' => 50, 'idle_timeout_s' => 60])
        ->and($world->agents->last('fn.release.apply')['payload']['limits']['memory_bytes'])->toBe(512 * 1024 * 1024);
});

it('lets viewers read the code but not change or deploy it', function () {
    [$world, $function] = fn_world();
    actingAsMember(Role::Viewer, $world->organization);

    $this->getJson(fn_url($world->site))->assertOk()->assertJsonPath('data.can.edit', false)->assertJsonPath('data.can.deploy', false);
    $this->putJson(fn_url($world->site, '/draft'), ['files' => ['index.ts' => 'x']])->assertForbidden();
    $this->postJson(fn_url($world->site, '/deploy'), ['files' => ['index.ts' => 'x']])->assertForbidden();
    $this->postJson(fn_url($world->site, '/versions/1/deploy'))->assertForbidden();
});

it('removes the function from its servers and forgets its code when the site is deleted', function () {
    [$world, $function] = fn_world();

    app(SiteFactory::class)->delete($world->site->id);

    expect($world->agents->last('fn.release.remove')['payload'])->toBe(['site' => $world->site->slug])
        ->and(CloudFunction::query()->count())->toBe(0)
        ->and(FunctionVersion::query()->count())->toBe(0);
});

it('hashes code independent of file order', function () {
    expect(Code::hash(Code::files(['b.ts' => '2', 'index.ts' => '1'], 'index.ts'), 'index.ts'))
        ->toBe(Code::hash(Code::files(['index.ts' => '1', 'b.ts' => '2'], 'index.ts'), 'index.ts'));
});
