<?php

use Illuminate\Support\Facades\Http;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\SiteSettings;
use Kiln\Identity\Contracts\Role;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\SourceControl\Contracts\Data\CommitData;
use Kiln\SourceControl\Events\PushReceived;

require_once __DIR__.'/../Support/helpers.php';

function hook_token(DeployWorld $world): string
{
    return SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->rotateHookToken();
}

it('deploys through the hook URL with reserved and custom query parameters', function () {
    $world = deploy_world();
    $token = hook_token($world);

    $response = $this->postJson("/api/deploy/{$token}?kiln_deploy_branch=release&kiln_deploy_commit=ABCDEF1234567&kiln_deploy_author=CI%20Bot&kiln_deploy_message=Ship%20it&ticket=OPS-1&build-number=42")
        ->assertAccepted()
        ->assertJsonStructure(['data' => ['id', 'status', 'number', 'url']]);

    $deployment = Deployment::query()->findOrFail($response->json('data.id'));
    expect($deployment->trigger)->toBe(Trigger::Hook)
        ->and($deployment->branch)->toBe('release')
        ->and($deployment->commit)->toBe('abcdef1234567')
        ->and($deployment->commit_author)->toBe('CI Bot')
        ->and($deployment->commit_message)->toBe('Ship it')
        ->and($deployment->variables)->toBe(['KILN_VAR_TICKET' => 'OPS-1', 'KILN_VAR_BUILD_NUMBER' => '42']);

    $world->builds->succeed();
    $hook = $world->agents->last('deploy.hook')['payload'];

    expect($hook['env'])->toMatchArray(['KILN_VAR_TICKET' => 'OPS-1', 'KILN_VAR_BUILD_NUMBER' => '42', 'KILN_BRANCH' => 'release', 'KILN_TRIGGER' => 'api'])
        ->and($hook['context']['trigger'])->toBe('api')
        ->and($world->builds->builds[$world->builds->last()]['request']->branch)->toBe('release');
});

it('accepts GET on the hook URL and rejects unknown or rotated tokens', function () {
    $world = deploy_world(actingAs: false);
    $old = hook_token($world);
    $new = hook_token($world);

    $this->getJson("/api/deploy/{$old}")->assertNotFound();
    $this->getJson('/api/deploy/short')->assertNotFound();
    $this->getJson("/api/deploy/{$new}")->assertAccepted();

    expect(Deployment::query()->count())->toBe(1);
});

it('validates the hook commit parameter', function () {
    $world = deploy_world(actingAs: false);
    $token = hook_token($world);

    $this->postJson("/api/deploy/{$token}?kiln_deploy_commit=not-a-sha")->assertUnprocessable()->assertJsonValidationErrors('commit');
});

it('deploys on push only for push-to-deploy sites on the pushed branch', function () {
    $world = deploy_world(site: ['push_to_deploy' => true]);
    $connection = $world->site->source_connection_id;
    $commit = new CommitData(str_repeat('b', 40), "Fix checkout\n\nDetails", 'Grace', 'grace@example.com');

    PushReceived::dispatch($world->organization->id, $connection, 'github', 'acme/shop', 'develop', $commit, 'grace');
    expect(Deployment::query()->count())->toBe(0);

    PushReceived::dispatch($world->organization->id, $connection, 'github', 'ACME/Shop', 'main', $commit, 'grace');

    $deployment = Deployment::query()->sole();
    expect($deployment->trigger)->toBe(Trigger::Push)
        ->and($deployment->commit)->toBe(str_repeat('b', 40))
        ->and($deployment->commit_author)->toBe('Grace')
        ->and($deployment->status)->toBe(DeploymentStatus::Building);
});

it('ignores pushes for sites without push-to-deploy or in another organization', function () {
    $world = deploy_world();
    $commit = new CommitData(str_repeat('b', 40), 'x', 'Grace', null);

    PushReceived::dispatch($world->organization->id, $world->site->source_connection_id, 'github', 'acme/shop', 'main', $commit);
    $world->site->forceFill(['push_to_deploy' => true])->save();
    PushReceived::dispatch('01jotherorg0000000000000000', $world->site->source_connection_id, 'github', 'acme/shop', 'main', $commit);

    expect(Deployment::query()->count())->toBe(0);
});

it('deploys from the site page and shows the stacked queue', function () {
    $world = deploy_world();

    $this->post("/sites/{$world->site->id}/deployments", ['branch' => 'main'])->assertRedirect();
    $this->post("/sites/{$world->site->id}/deployments")->assertRedirect();

    $this->getJson("/sites/{$world->site->id}/deployments")->assertOk()
        ->assertJsonPath('data.active.status', 'building')
        ->assertJsonCount(1, 'data.queued')
        ->assertJsonPath('data.queued.0.number', 2)
        ->assertJsonPath('data.can.create', true);
    $this->get("/sites/{$world->site->id}/deployments")->assertRedirect();

    $deployment = Deployment::query()->where('number', 1)->sole();
    $this->getJson("/sites/{$world->site->id}/deployments/{$deployment->id}")->assertOk()
        ->assertJsonPath('data.deployment.id', $deployment->id)
        ->assertJsonCount(1, 'data.targets')
        ->assertJsonCount(1, 'data.steps')
        ->assertJsonPath('data.steps.0.kind', 'build');
    $this->get("/sites/{$world->site->id}/deployments/{$deployment->id}")->assertRedirect();

    $this->getJson("/sites/{$world->site->id}/deployments/{$deployment->id}/state?after=0")->assertOk()
        ->assertJsonPath('data.deployment.status', 'building')
        ->assertJsonStructure(['data' => ['deployment', 'targets' => [['steps']], 'steps', 'lines']]);
});

it('forbids viewers from deploying and hides other organizations', function () {
    $world = deploy_world(role: Role::Viewer);

    $this->getJson("/sites/{$world->site->id}/deployments")->assertOk();
    $this->post("/sites/{$world->site->id}/deployments")->assertForbidden();
    $this->put("/sites/{$world->site->id}/deploy-settings", [])->assertForbidden();

    actingAsMember();
    $this->get("/sites/{$world->site->id}/deployments")->assertNotFound();
});

it('updates deploy settings and validates the strategy against the runtime', function () {
    $world = deploy_world();
    $input = [
        'strategy' => 'rolling', 'batch_size' => 2, 'keep_releases' => 7, 'health_enabled' => true, 'health_path' => '/healthz',
        'health_status' => 204, 'health_timeout_s' => 5, 'health_retries' => 4, 'health_retry_delay_s' => 2,
    ];

    $this->put("/sites/{$world->site->id}/deploy-settings", $input)->assertRedirect()->assertSessionHasNoErrors();
    $this->put("/sites/{$world->site->id}/deploy-settings", [...$input, 'strategy' => 'blue-green'])->assertSessionHasErrors('strategy');

    $settings = SiteSettings::query()->findOrFail($world->site->id);
    expect($settings->strategy->value)->toBe('rolling')
        ->and($settings->keep_releases)->toBe(7)
        ->and($settings->health_path)->toBe('/healthz');

    $this->post("/sites/{$world->site->id}/deploy-settings/hook")->assertRedirect();
    $settings = $this->getJson("/sites/{$world->site->id}/deploy-settings")->assertOk()
        ->assertJsonPath('data.settings.strategy', 'rolling')
        ->assertJsonCount(4, 'data.strategies');
    expect((string) $settings->json('data.hookUrl'))->toContain('/api/deploy/');
    $this->get("/sites/{$world->site->id}/deploy-settings")->assertRedirect();

    $this->put("/sites/{$world->site->id}/deploy-settings/push-to-deploy", ['enabled' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($world->site->refresh()->push_to_deploy)->toBeTrue();
});

it('uses the configured health check path, status and domain', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];
    $this->put("/sites/{$world->site->id}/deploy-settings", [
        'strategy' => 'zero-downtime', 'batch_size' => 1, 'keep_releases' => 5, 'health_enabled' => true, 'health_path' => '/healthz',
        'health_status' => 200, 'health_timeout_s' => 5, 'health_retries' => 1, 'health_retry_delay_s' => 0,
    ]);

    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded);
    Http::assertSent(fn ($request) => $request->url() === 'https://shop.example.com/healthz');
});

it('skips the health check and restart when disabled or without programs', function () {
    $world = deploy_world();
    $world->processes->noPrograms = true;
    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill(['health_enabled' => false])->save();

    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($world->agents->dispatched('proc.restart'))->toBe([])
        ->and($world->processes->restarts)->toBe([['site' => $world->site->id, 'server' => $world->servers[0]->id]]);
    Http::assertNothingSent();
});

it('fails fast when the site cannot be deployed', function () {
    $world = deploy_world(site: ['deploy_script' => "\$KILN_ACTIVATE\n\$KILN_FETCH\n"]);
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('must come before');
    $world->agents->assertNothingDispatched();
    expect($world->builds->builds)->toBe([]);
});
