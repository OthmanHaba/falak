<?php

use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Identity\Application\Actions\CreateApiToken;
use Kiln\Identity\Contracts\Role;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Telemetry\Contracts\Data\LogLine;
use Kiln\Telemetry\Contracts\LogsQuery;

require_once __DIR__.'/../Support/helpers.php';

/**
 * API world: a token (abilities) for the site's organization; requests go through Sanctum only.
 *
 * @param  list<string>  $abilities
 */
function api_world(array $abilities = ['*'], int $servers = 1, Role $role = Role::Owner): array
{
    $world = deploy_world(servers: $servers, role: $role, actingAs: false);
    $token = app(CreateApiToken::class)($world->user, $world->organization->id, 'cli', $abilities)->plainTextToken;

    return [$world, $token];
}

function api_deploy_ok(DeployWorld $world): Deployment
{
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Api);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    return $deployment->refresh();
}

it('lists and shows sites in the shape the CLI decodes (by id or slug)', function () {
    [$world, $token] = api_world();
    $deployment = api_deploy_ok($world);

    $this->withToken($token)->getJson('/api/v1/sites')->assertOk()
        ->assertJsonPath('data.0.id', $world->site->id)
        ->assertJsonPath('data.0.slug', $world->site->slug)
        ->assertJsonPath('data.0.runtime', 'frankenphp')
        ->assertJsonPath('data.0.build_mode', 'native')
        ->assertJsonPath('data.0.strategy', 'zero-downtime')
        ->assertJsonPath('data.0.branch', 'main')
        ->assertJsonPath('data.0.server_ids', $world->serverIds())
        ->assertJsonPath('data.0.current_release.id', $deployment->release_id)
        ->assertJsonPath('data.0.current_release.active', true);

    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->slug}")->assertOk()->assertJsonPath('data.id', $world->site->id);
    $this->withToken($token)->getJson('/api/v1/sites/nope')->assertNotFound()->assertJsonStructure(['message']);
});

it('creates a deployment and follows it: show, output with ?after cursor, list', function () {
    [$world, $token] = api_world(['deployments.view', 'deployments.create']);

    $created = $this->withToken($token)->postJson("/api/v1/sites/{$world->site->slug}/deployments", ['branch' => 'main'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'site_id', 'status', 'trigger', 'branch', 'commit', 'message', 'release_id', 'url', 'created_at']])
        ->assertJsonPath('data.status', 'building')
        ->assertJsonPath('data.trigger', 'api');

    $id = $created->json('data.id');
    expect($created->json('data.url'))->toEndWith("/sites/{$world->site->id}/deployments/{$id}");

    $world->builds->succeed();
    deploy_run_all($world->agents);

    $this->withToken($token)->getJson("/api/v1/deployments/{$id}")->assertOk()
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.targets.0.server_name', 'web-1')
        ->assertJsonPath('data.targets.0.status', 'succeeded');

    $all = $this->withToken($token)->getJson("/api/v1/deployments/{$id}/output?after=0")->assertOk()
        ->assertJsonStructure(['data' => [['seq', 'at', 'server', 'phase', 'stream', 'data']], 'meta' => ['next']]);
    $lines = $all->json('data');
    $middle = $lines[intdiv(count($lines), 2)]['seq'];

    $rest = $this->withToken($token)->getJson("/api/v1/deployments/{$id}/output?after={$middle}")->json();
    expect(array_column($rest['data'], 'seq'))->each->toBeGreaterThan($middle)
        ->and($rest['meta']['next'])->toBe(end($lines)['seq'])
        ->and(array_unique(array_filter(array_column($lines, 'phase'))))->toContain('fetch', 'prepare', 'migrate', 'activate', 'restart', 'healthcheck');

    $this->withToken($token)->getJson("/api/v1/deployments/{$id}/output?after=".end($lines)['seq'])->assertOk()
        ->assertJsonPath('data', [])->assertJsonPath('meta.next', end($lines)['seq']);

    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->id}/deployments")->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('meta.total', 1);
});

it('returns Laravel 422 bodies the CLI decodes', function () {
    [$world, $token] = api_world();

    $this->withToken($token)->postJson("/api/v1/sites/{$world->site->id}/deployments", ['branch' => 'bad branch'])
        ->assertUnprocessable()->assertJsonStructure(['message', 'errors' => ['branch']]);

    $this->withToken($token)->postJson("/api/v1/sites/{$world->site->id}/rollback")
        ->assertUnprocessable()->assertJsonPath('errors.release_id.0', 'There is no earlier release to roll back to.');
});

it('rolls back through the API and lists releases', function () {
    [$world, $token] = api_world(servers: 2);
    $first = api_deploy_ok($world);
    $second = api_deploy_ok($world);

    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->id}/releases")->assertOk()
        ->assertJsonPath('data.0.id', $second->release_id)
        ->assertJsonPath('data.0.active', true)
        ->assertJsonPath('data.1.id', $first->release_id)
        ->assertJsonPath('data.1.active', false)
        ->assertJsonPath('data.1.deployment_id', $first->id);

    $rollback = $this->withToken($token)->postJson("/api/v1/sites/{$world->site->slug}/rollback", ['release_id' => strtoupper($first->release_id)])
        ->assertCreated()->assertJsonPath('data.trigger', 'rollback')->assertJsonPath('data.release_id', $first->release_id);

    deploy_run_all($world->agents);

    $this->withToken($token)->getJson('/api/v1/deployments/'.$rollback->json('data.id'))->assertJsonPath('data.status', 'succeeded');
    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->id}/releases")->assertJsonPath('data.0.id', $first->release_id);
});

it('enforces token abilities and organization pinning', function () {
    [$world, $token] = api_world(['deployments.view']);
    $deployment = api_deploy_ok($world);

    $this->withToken($token)->getJson("/api/v1/deployments/{$deployment->id}")->assertOk();
    $this->withToken($token)->postJson("/api/v1/sites/{$world->site->id}/deployments")->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/sites/{$world->site->id}/rollback")->assertForbidden();

    [$other, $otherToken] = api_world();
    app('auth')->forgetGuards();
    $this->withToken($otherToken)->getJson("/api/v1/deployments/{$deployment->id}")->assertNotFound();
    $this->withToken($otherToken)->getJson("/api/v1/sites/{$world->site->id}/releases")->assertNotFound();

    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson("/api/v1/deployments/{$deployment->id}")->assertUnauthorized();
});

it('reads and writes the site environment as dotenv content', function () {
    [$world, $token] = api_world(['sites.view', 'sites.env.view', 'sites.env.manage']);

    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->id}/env")->assertOk()
        ->assertJsonPath('data.content', "APP_ENV=production\nAPP_KEY=base64:secret\nKILN_SITE_ID=stale\n");

    $this->withToken($token)->putJson("/api/v1/sites/{$world->site->slug}/env", ['content' => "APP_ENV=staging\nNEW=\"a b\"\n"])->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.changed', true);

    expect(app(SiteDirectory::class)->environment($world->site->id)->variables)->toBe(['APP_ENV' => 'staging', 'NEW' => 'a b'])
        ->and(app(SiteDirectory::class)->environment($world->site->id)->exposedToDeployScript)->toBe(['APP_ENV']);

    $this->withToken($token)->putJson("/api/v1/sites/{$world->site->id}/env", ['content' => "not valid\n"])
        ->assertUnprocessable()->assertJsonValidationErrors('content');
});

it('requires the env abilities for environment access', function () {
    [$world, $token] = api_world(['sites.view']);

    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->id}/env")->assertForbidden();
    $this->withToken($token)->putJson("/api/v1/sites/{$world->site->id}/env", ['content' => ''])->assertForbidden();
});

it('returns site logs newest first with a cursor', function () {
    [$world, $token] = api_world(['telemetry.view']);
    $captured = [];

    app()->instance(LogsQuery::class, new class($captured) implements LogsQuery
    {
        public function __construct(public array &$captured) {}

        public function queryRange(string $logql, DateTimeInterface $start, DateTimeInterface $end, int $limit = 200, string $direction = 'backward'): array
        {
            $this->captured[] = [$logql, $limit];

            return [
                new LogLine('1790000000000000002', 'boom', ['kiln_server_id' => 'X', 'service_name' => 'laravel', 'detected_level' => 'error'], ['severity_text' => 'ERROR']),
                new LogLine('1790000000000000001', 'hello', ['service_name' => 'laravel'], []),
            ];
        }
    });

    $response = $this->withToken($token)->getJson("/api/v1/sites/{$world->site->slug}/logs?since=600&limit=2&level=error")->assertOk()
        ->assertJsonPath('data.0.message', 'boom')
        ->assertJsonPath('data.0.level', 'ERROR')
        ->assertJsonPath('data.0.source', 'laravel')
        ->assertJsonPath('meta.cursor', '1790000000000000001');

    expect($captured[0][0])->toContain('kiln_site_id="'.strtoupper($world->site->id).'"')
        ->toContain('kiln_org_id="'.strtoupper($world->organization->id).'"')
        ->and($captured[0][1])->toBe(2)
        ->and($response->json('data.0.at'))->toStartWith('2026-');

    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->id}/logs?limit=5")->assertJsonPath('meta.cursor', '');
});

it('returns the site access log with filters and a cursor', function () {
    [$world, $token] = api_world(['telemetry.view']);
    $captured = [];

    app()->instance(LogsQuery::class, new class($captured) implements LogsQuery
    {
        public function __construct(public array &$captured) {}

        public function queryRange(string $logql, DateTimeInterface $start, DateTimeInterface $end, int $limit = 200, string $direction = 'backward'): array
        {
            $this->captured[] = [$logql, $limit];

            return [
                new LogLine('1790000000000000002', 'GET /cart?x=1 502 12.3ms', ['service_name' => 'shop', 'kiln_server_id' => '01JSERVER0000000000000000A', 'kiln_log_kind' => 'access'], [
                    'http_request_method' => 'GET', 'url_path' => '/cart', 'url_query' => 'x=1', 'http_response_status_code' => '502',
                    'http_server_duration_ms' => '12.300', 'http_response_body_size' => '512', 'client_address' => '203.0.113.9',
                    'user_agent_original' => 'curl/8', 'server_address' => 'shop.test', 'kiln_deployment_id' => '01JDEP0000000000000000000A',
                ]),
            ];
        }
    });

    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->slug}/access-logs?since=600&limit=1&status=5xx&method=get&path=/cart&deployment=01jdep0000000000000000000a")->assertOk()
        ->assertJsonPath('data.0.method', 'GET')
        ->assertJsonPath('data.0.path', '/cart')
        ->assertJsonPath('data.0.status', 502)
        ->assertJsonPath('data.0.duration_ms', 12.3)
        ->assertJsonPath('data.0.bytes', 512)
        ->assertJsonPath('data.0.client_ip', '203.0.113.9')
        ->assertJsonPath('data.0.server_id', '01jserver0000000000000000a')
        ->assertJsonPath('data.0.deployment_id', '01jdep0000000000000000000a')
        ->assertJsonPath('meta.cursor', '1790000000000000002');

    expect($captured[0][0])->toBe('{kiln_org_id="'.strtoupper($world->organization->id).'", service_name="'.$world->site->slug.'", kiln_log_kind="access"}'
        .' |= "/cart" | kiln_deployment_id="01JDEP0000000000000000000A" | http_request_method="GET" | http_response_status_code=~"5.."');

    $this->withToken($token)->getJson("/api/v1/sites/{$world->site->id}/access-logs?status=99")->assertUnprocessable();
});

it('lists the token organization', function () {
    [$world, $token] = api_world(['deployments.view']);

    $this->withToken($token)->getJson('/api/v1/organizations')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $world->organization->id)
        ->assertJsonPath('data.0.role', 'owner');
});
