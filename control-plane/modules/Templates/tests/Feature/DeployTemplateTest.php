<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Templates\Domain\Models\CustomTemplate;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';

beforeEach(function () {
    templates_fixture_catalog();
    $this->fakes = templates_fakes();
    sites_fake_agents();
    sites_fake_source_control();
    config(['sites.test_domain' => 'kiln.test']);

    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->server = sites_server($this->organization->id, docker: true);
    $this->project = Project::query()->where('organization_id', $this->organization->id)->where('is_default', true)->firstOrFail();
    $this->url = "/projects/{$this->project->id}/production/templates/hello/deploy";
});

function deploy_payload(array $overrides = []): array
{
    return array_replace_recursive([
        'inputs' => ['ADMIN_EMAIL' => 'ops@example.com', 'APP_SECRET' => 'from-the-form-0123456789'],
        'domains' => ['admin' => 'Admin.Example.com'],
        'server_ids' => [test()->server->id],
        'position' => ['x' => 480, 'y' => 240],
    ], $overrides);
}

it('creates the compose site with exactly the §5 fields, places it and starts the first deploy', function () {
    $response = $this->postJson($this->url, deploy_payload())->assertCreated();

    $payload = $this->fakes['sites']->last();
    $site = Site::query()->findOrFail($response->json('data.site_id'));

    expect(array_keys($payload))->toBe(['name', 'slug', 'framework', 'runtime', 'server_ids', 'leader_server_id', 'compose_source', 'compose_content', 'public_services', 'variables', 'template'])
        ->and($payload['name'])->toBe('Hello Stack')
        ->and($payload['slug'])->toBe('hello-stack')
        ->and($payload['runtime'])->toBe('compose')
        ->and($payload['compose_source'])->toBe('inline')
        ->and($payload['server_ids'])->toBe([$this->server->id])
        ->and($payload['leader_server_id'])->toBe($this->server->id)
        ->and($payload['public_services'])->toBe([
            ['service' => 'web', 'port' => 8080, 'domain' => null],
            ['service' => 'admin', 'port' => 9000, 'domain' => 'admin.example.com'],
        ])
        ->and($payload['template'])->toBe(['slug' => 'hello', 'version' => '1.2.0', 'source' => 'catalog']);

    // Kiln placeholders rendered once (test domain for web, the custom domain for admin); Compose interpolation kept.
    expect($payload['compose_content'])
        ->toContain('APP_URL: https://hello-stack.kiln.test')
        ->toContain('ADMIN_HOST: admin.example.com')
        ->toContain('SITE: hello-stack')
        ->toContain('APP_SECRET: ${APP_SECRET}')
        ->toContain('"$$NOT_A_VARIABLE"')
        ->not->toContain('${{ kiln.');

    expect($payload['variables'])->toMatchArray([
        'APP_SECRET' => 'from-the-form-0123456789',
        'ADMIN_EMAIL' => 'ops@example.com',
        'TIMEZONE' => 'UTC',
        'SIGNUPS' => 'false',
        'WORKERS' => '2',
        'PUBLIC_URL' => 'https://hello-stack.kiln.test',
        'DATABASE_URL' => '${{ postgres.DATABASE_URL }}',
    ])->and($payload['variables']['DB_PASSWORD'])->toHaveLength(20);

    // Placed where the user dropped it, under the chosen name.
    $service = app(ProjectDirectory::class)->projectOf(ServiceKind::Site, $site->id);
    expect($service->environmentId)->toBe($this->project->production()->id)
        ->and([$service->x, $service->y])->toBe([480, 240])
        ->and($service->name)->toBe('Hello Stack');

    expect($this->fakes['deployments']->deployed)->toHaveCount(1)
        ->and($this->fakes['deployments']->deployed[0]['site_id'])->toBe($site->id)
        ->and($this->fakes['deployments']->deployed[0]['requested_by'])->toBe($this->user->id);

    $response->assertJsonPath('data.deployment_id', $this->fakes['deployments']->deployed[0]['id'])
        ->assertJsonPath('data.service_id', $service->id)
        ->assertJsonPath('data.panel_url', "/projects/{$this->project->id}/production/service/site/{$site->id}/deployments")
        ->assertJsonPath('data.domains', ['web' => 'hello-stack.kiln.test', 'admin' => 'admin.example.com']);

    $this->assertDatabaseHas('identity_audit_log', ['action' => 'templates.deployed', 'subject_id' => $site->id]);
});

it('generates secrets on the server when the form leaves them empty', function () {
    $this->postJson($this->url, deploy_payload(['inputs' => ['APP_SECRET' => '']]))->assertCreated();
    $this->postJson($this->url, deploy_payload(['inputs' => ['APP_SECRET' => ''], 'domains' => ['admin' => 'admin2.example.com']]))->assertCreated();

    [$a, $b] = array_map(fn ($c) => $c['data']['variables']['APP_SECRET'], $this->fakes['sites']->created);
    expect($a)->toMatch('/^[A-Za-z0-9]{32}$/')->and($a)->not->toBe($b);
});

it('picks a free name and slug', function () {
    $this->fakes['sites']->takenSlugs = ['hello-stack-2'];
    $this->postJson($this->url, deploy_payload())->assertCreated();
    $this->postJson($this->url, deploy_payload(['domains' => ['admin' => 'b.example.com']]))->assertCreated();

    $second = $this->fakes['sites']->last();
    expect($second['name'])->toBe('Hello Stack-2')
        ->and($second['slug'])->toBe('hello-stack-2-2')
        ->and($second['compose_content'])->toContain('https://hello-stack-2-2.kiln.test');
});

it('validates inputs, domains and servers', function () {
    $this->postJson($this->url, deploy_payload(['inputs' => ['ADMIN_EMAIL' => 'nope']]))->assertUnprocessable()->assertJsonValidationErrors('inputs.ADMIN_EMAIL');
    $this->postJson($this->url, deploy_payload(['domains' => ['admin' => 'not a domain']]))->assertUnprocessable()->assertJsonValidationErrors('domains.admin');
    $this->postJson($this->url, deploy_payload(['domains' => ['web' => 'x.example.com', 'admin' => 'x.example.com']]))->assertUnprocessable()->assertJsonValidationErrors('domains.admin');
    $this->postJson($this->url, [...deploy_payload(), 'server_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('server_ids');

    // No test domain and generated domains off: the web service needs a domain.
    config(['sites.test_domain' => null, 'edge.generated_domain_suffix' => 'off']);
    $this->postJson($this->url, deploy_payload())->assertUnprocessable()->assertJsonValidationErrors('domains.web');
    $this->postJson($this->url, deploy_payload(['domains' => ['web' => ['type' => 'test']]]))->assertUnprocessable()->assertJsonValidationErrors('domains.web');
    $this->postJson($this->url, deploy_payload(['domains' => ['web' => ['type' => 'bogus']]]))->assertUnprocessable()->assertJsonValidationErrors('domains.web');
    $this->postJson($this->url, deploy_payload(['domains' => ['web' => ['type' => 'custom']]]))->assertUnprocessable()->assertJsonValidationErrors('domains.web');

    expect($this->fakes['sites']->created)->toBe([]);
});

it('generates a domain per public service when no test domain is configured', function () {
    config(['sites.test_domain' => null]);
    $this->server->forceFill(['ipv4' => '63.182.218.247'])->save();

    $response = $this->postJson($this->url, deploy_payload(['domains' => ['admin' => ['type' => 'custom', 'name' => 'https://Admin.Example.com/']]]))->assertCreated();

    expect($this->fakes['sites']->last()['public_services'])->toBe([
        ['service' => 'web', 'port' => 8080, 'domain' => 'web-hello-stack.63-182-218-247.sslip.io'],
        ['service' => 'admin', 'port' => 9000, 'domain' => 'admin.example.com'],
    ])->and($response->json('data.domains'))->toBe([
        'web' => 'web-hello-stack.63-182-218-247.sslip.io',
        'admin' => 'admin.example.com',
    ])->and($this->fakes['sites']->last()['compose_content'])->toContain('APP_URL: https://web-hello-stack.63-182-218-247.sslip.io');
});

it('accepts explicit generated, test and custom domain choices', function () {
    $this->server->forceFill(['ipv4' => '203.0.113.9'])->save();

    $this->postJson($this->url, deploy_payload(['domains' => [
        'web' => ['type' => 'generated'],
        'admin' => ['type' => 'test'],
    ]]))->assertCreated();

    expect($this->fakes['sites']->last()['public_services'])->toBe([
        ['service' => 'web', 'port' => 8080, 'domain' => 'web-hello-stack.203-0-113-9.sslip.io'],
        ['service' => 'admin', 'port' => 9000, 'domain' => null],
    ]);
});

it('explains why a domain cannot be generated', function () {
    config(['sites.test_domain' => null]);
    $this->server->forceFill(['ipv4' => null])->save();

    $errors = $this->postJson($this->url, deploy_payload(['domains' => ['web' => ['type' => 'generated']]]))->assertUnprocessable()->json('errors');

    expect($errors['domains.web'][0])->toContain('has no public IPv4 address yet');
});

it('surfaces a failed first deploy as a warning', function () {
    $this->fakes['deployments']->fail = true;

    $this->postJson($this->url, deploy_payload())->assertCreated()
        ->assertJsonPath('data.deployment_id', null)
        ->assertJsonPath('warnings.0', 'The first deploy did not start: Connect a repository before deploying.');
});

it('deploys custom templates of the organization', function () {
    CustomTemplate::query()->create([
        'organization_id' => $this->organization->id, 'slug' => 'ours', 'name' => 'Ours', 'version' => '3.0.0', 'category' => 'cms', 'description' => 'Ours',
        'template_yaml' => str_replace(['slug: hello', 'version: 1.2.0'], ['slug: ours', 'version: 3.0.0'], TEMPLATES_FIXTURE_TEMPLATE), 'compose_yaml' => TEMPLATES_FIXTURE_COMPOSE,
    ]);

    $this->postJson("/projects/{$this->project->id}/production/templates/ours/deploy", deploy_payload(['source' => 'custom', 'name' => 'blog']))->assertCreated();

    expect($this->fakes['sites']->last()['template'])->toBe(['slug' => 'ours', 'version' => '3.0.0', 'source' => 'custom'])
        ->and($this->fakes['sites']->last()['name'])->toBe('blog');
});

it('authorizes and scopes to the organization', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->postJson($this->url, deploy_payload())->assertForbidden();

    actingAsMember(Role::Owner);
    $this->postJson($this->url, deploy_payload())->assertNotFound();

    $this->actingAs($this->user)->postJson("/projects/{$this->project->id}/nope/templates/hello/deploy", deploy_payload())->assertNotFound();
    $this->postJson("/projects/{$this->project->id}/production/templates/nope/deploy", deploy_payload())->assertNotFound();
});

it('deploys templates over the public API with a token', function () {
    Sanctum::actingAs($this->user, ['*']);
    $token = $this->user->createToken('cli', ['*'])->accessToken;
    $token->forceFill(['organization_id' => $this->organization->id])->save();
    $this->user->withAccessToken($token);

    $this->postJson("/api/v1/projects/{$this->project->id}/production/templates/hello/deploy", deploy_payload())
        ->assertCreated()
        ->assertJsonStructure(['data' => ['site_id']]);
});
