<?php

use Illuminate\Support\Facades\Event;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Application\Jobs\ReconcileDeployments;
use Kiln\Deployments\Application\Orchestration\DeploymentQueue;
use Kiln\Deployments\Application\Orchestration\Orchestrator;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\OutputLine;
use Kiln\Deployments\Events\DeploymentFailed;
use Kiln\Identity\Application\Actions\CreateApiToken;
use Kiln\Sites\Application\TargetProvisioner;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\SiteTarget;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\SourceControl\Contracts\Data\CommitData;
use Kiln\SourceControl\Events\PushReceived;

require_once __DIR__.'/../Support/helpers.php';

/**
 * Put the site's targets (by server index) into a preparation state without dispatching anything.
 *
 * @param  array<int, TargetStatus>  $statuses
 */
function waiting_targets(DeployWorld $world, array $statuses): void
{
    foreach ($statuses as $index => $status) {
        SiteTarget::query()->where('site_id', $world->site->id)->where('server_id', $world->servers[$index]->id)
            ->update(['status' => $status, 'status_message' => $status === TargetStatus::Failed ? 'Installing the Bun runtime failed: download stalled' : null]);
    }
}

/** Sites finishes preparing the site on a server (SiteTargetReady → the queued listener). */
function waiting_ready(DeployWorld $world, int $index): void
{
    $target = SiteTarget::query()->with('site')->where('site_id', $world->site->id)->where('server_id', $world->servers[$index]->id)->sole();
    $target->forceFill(['step' => 'runtime'])->save();
    app(TargetProvisioner::class)->advance($target);
}

/** Sites fails preparing the site on a server (SiteTargetFailed). */
function waiting_fail(DeployWorld $world, int $index, string $reason = 'Creating the site user failed: exit code 1'): void
{
    $target = SiteTarget::query()->with('site')->where('site_id', $world->site->id)->where('server_id', $world->servers[$index]->id)->sole();
    app(TargetProvisioner::class)->fail($target, $reason);
}

function waiting_deploy(DeployWorld $world, Trigger $trigger = Trigger::Manual, ?string $commit = null): Deployment
{
    return app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), $trigger, commit: $commit);
}

/** @return list<string> */
function waiting_log(Deployment $deployment): array
{
    return OutputLine::query()->where('deployment_id', $deployment->id)->orderBy('id')->pluck('data')->map(fn ($l) => trim((string) $l))->all();
}

it('waits for preparing servers and starts once they are ready', function () {
    $world = deploy_world(servers: 2);
    waiting_targets($world, [TargetStatus::Provisioning, TargetStatus::Pending]);

    $deployment = waiting_deploy($world);

    expect($deployment->status)->toBe(DeploymentStatus::Waiting)
        ->and($deployment->waiting_since)->not->toBeNull()
        ->and($deployment->waiting_reason)->toBe('Waiting for 2 servers to finish preparing: web-1, web-2')
        ->and($deployment->steps()->count())->toBe(0)
        ->and($world->builds->builds)->toBe([]);
    $world->agents->assertNothingDispatched();

    $this->getJson("/sites/{$world->site->id}/deployments")->assertOk()
        ->assertJsonPath('data.active.id', $deployment->id)
        ->assertJsonPath('data.active.status', 'waiting')
        ->assertJsonPath('data.active.waiting_reason', 'Waiting for 2 servers to finish preparing: web-1, web-2')
        ->assertJsonCount(0, 'data.queued');

    // One ready: still waiting for the other (all preparing servers are waited for), with a fresher reason.
    waiting_ready($world, 1);
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Waiting)
        ->and($deployment->waiting_reason)->toBe('Waiting for 1 server to finish preparing: web-1');

    waiting_ready($world, 0);
    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Building)
        ->and($deployment->waiting_reason)->toBeNull()
        ->and($deployment->targets()->count())->toBe(2);

    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(waiting_log($deployment))->toContain(
            'Waiting for 2 servers to finish preparing: web-1, web-2. The deployment starts automatically once they are ready.',
            "The site's servers are ready; starting the deployment.",
        );
});

it('waits for a late server even when another is ready; the reconciler recovers a missed readiness event', function () {
    $world = deploy_world(servers: 2);
    waiting_targets($world, [1 => TargetStatus::Provisioning]);

    expect(waiting_deploy($world)->status)->toBe(DeploymentStatus::Waiting);

    // The reconciler picks up a readiness event that was lost.
    SiteTarget::query()->where('server_id', $world->servers[1]->id)->update(['status' => TargetStatus::Ready]);
    (new ReconcileDeployments)->handle(app(Orchestrator::class), app(DeploymentQueue::class));

    expect(Deployment::query()->sole()->status)->toBe(DeploymentStatus::Building);
});

it('skips servers whose preparation failed as long as another one is ready', function () {
    $world = deploy_world(servers: 3);
    waiting_targets($world, [1 => TargetStatus::Provisioning, 2 => TargetStatus::Provisioning]);
    $deployment = waiting_deploy($world);

    waiting_fail($world, 2, 'Installing the Bun runtime failed: download stalled');
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Waiting)
        ->and($deployment->waiting_reason)->toBe('Waiting for 1 server to finish preparing: web-2');

    waiting_ready($world, 1);
    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Building)
        ->and($deployment->targets()->pluck('server_name')->all())->toBe(['web-1', 'web-2'])
        ->and(waiting_log($deployment))->toContain("web-3 is skipped: preparing the site failed (Installing the Bun runtime failed: download stalled). Retry it from the site's servers, then redeploy.");

    $world->builds->succeed();
    deploy_run_all($world->agents);
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($world->agents->dispatched(null, $world->servers[2]->id))->toBe([]);
});

it('fails with the reason when the leader cannot be prepared, and lets the next deployment go', function () {
    Event::fake([DeploymentFailed::class]);
    $world = deploy_world(servers: 2);
    waiting_targets($world, [TargetStatus::Provisioning, TargetStatus::Provisioning]);
    $deployment = waiting_deploy($world);

    waiting_fail($world, 0, 'Creating the site user failed: exit code 1');

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($deployment->error)->toBe('Preparing the site failed on its leader server web-1 (Creating the site user failed: exit code 1). Retry the server (site Settings → Servers) or pick another leader, then deploy again.');
    Event::assertDispatched(DeploymentFailed::class, fn (DeploymentFailed $e) => $e->deploymentId === $deployment->id);
    $world->agents->assertNothingDispatched();

    // Every server failed: the next trigger fails fast with every reason.
    waiting_fail($world, 1, 'Configuring the PHP-FPM pool failed: exit code 2');
    $next = waiting_deploy($world);
    expect($next->status)->toBe(DeploymentStatus::Failed)
        ->and($next->error)->toStartWith('Preparing the site failed on its leader server web-1');
});

it('fails a member-only failure when no server is left to deploy to', function () {
    $world = deploy_world(servers: 2);
    SiteTarget::query()->where('server_id', $world->servers[0]->id)->delete();
    waiting_targets($world, [1 => TargetStatus::Provisioning]);
    SiteTarget::query()->where('server_id', $world->servers[1]->id)->update(['role' => 'member']);

    $deployment = waiting_deploy($world);
    expect($deployment->status)->toBe(DeploymentStatus::Waiting);

    waiting_fail($world, 1, 'Creating the site user failed: exit code 1');

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toBe('Preparing the site failed on every server: web-2 (Creating the site user failed: exit code 1).');
});

it('times out after the configured minutes', function () {
    Event::fake([DeploymentFailed::class]);
    config(['deployments.waiting.timeout_minutes' => 30]);
    $world = deploy_world();
    waiting_targets($world, [TargetStatus::Provisioning]);
    $deployment = waiting_deploy($world);

    $this->travel(29)->minutes();
    (new ReconcileDeployments)->handle(app(Orchestrator::class), app(DeploymentQueue::class));
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Waiting);

    $this->travel(2)->minutes();
    (new ReconcileDeployments)->handle(app(Orchestrator::class), app(DeploymentQueue::class));

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toStartWith("Timed out after 30 minutes waiting for the site's servers to finish preparing (web-1).");
    Event::assertDispatched(DeploymentFailed::class);
});

it('coalesces repeated triggers into the waiting deployment (latest commit wins) from push, API and the panel', function () {
    $world = deploy_world(site: ['push_to_deploy' => true]);
    waiting_targets($world, [TargetStatus::Provisioning]);
    $token = app(CreateApiToken::class)($world->user, $world->organization->id, 'cli', ['*'])->plainTextToken;

    $first = waiting_deploy($world, commit: str_repeat('1', 40));
    expect($first->status)->toBe(DeploymentStatus::Waiting);

    PushReceived::dispatch($world->organization->id, $world->site->source_connection_id, 'github', 'acme/shop', 'main',
        new CommitData(str_repeat('2', 40), 'Second push', 'Grace', null), 'grace');

    $this->withToken($token)->postJson("/api/v1/sites/{$world->site->slug}/deployments", ['commit' => str_repeat('3', 40)])
        ->assertCreated()
        ->assertJsonPath('data.id', $first->id)
        ->assertJsonPath('data.status', 'waiting')
        ->assertJsonPath('data.commit', str_repeat('3', 40))
        ->assertJsonPath('data.waiting_reason', 'Waiting for 1 server to finish preparing: web-1');

    expect(Deployment::query()->count())->toBe(1)
        ->and($first->refresh()->number)->toBe(1)
        ->and($first->trigger)->toBe(Trigger::Api)
        ->and($first->commit)->toBe(str_repeat('3', 40))
        ->and(waiting_log($first))->toContain(
            'Updated by '.Trigger::Push->label().' to 2222222 while waiting for the servers (the latest trigger wins).',
            'Updated by '.Trigger::Api->label().' to 3333333 while waiting for the servers (the latest trigger wins).',
        );

    waiting_ready($world, 0);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($first->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(array_values($world->builds->builds)[0]['commit'])->toBe(str_repeat('3', 40));
});

it('cancels a waiting deployment from the panel and the API, then starts the next one', function () {
    $world = deploy_world();
    waiting_targets($world, [TargetStatus::Provisioning]);
    $token = app(CreateApiToken::class)($world->user, $world->organization->id, 'cli', ['*'])->plainTextToken;

    $deployment = waiting_deploy($world);
    $this->getJson("/sites/{$world->site->id}/deployments/{$deployment->id}")->assertOk()->assertJsonPath('data.can.cancel', true);

    $this->postJson("/sites/{$world->site->id}/deployments/{$deployment->id}/cancel")->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
    expect($deployment->refresh()->error)->toBe('Cancelled before it started.');

    // A rollback-free queue behind it: the next trigger waits again, and the API cancels it too.
    $next = waiting_deploy($world);
    expect($next->status)->toBe(DeploymentStatus::Waiting);
    $this->withToken($token)->postJson("/api/v1/deployments/{$next->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->withToken($token)->postJson("/api/v1/deployments/{$next->id}/cancel")->assertUnprocessable();

    // Nothing waits any more: once the server is ready, a new deployment starts straight away.
    waiting_ready($world, 0);
    expect(waiting_deploy($world)->status)->toBe(DeploymentStatus::Building);
});

it('does not fold rollbacks into a waiting deployment; they queue behind it', function () {
    $world = deploy_world();

    foreach ([1, 2] as $n) {
        waiting_deploy($world);
        $world->builds->succeed();
        deploy_run_all($world->agents);
    }

    waiting_targets($world, [TargetStatus::Provisioning]);
    $waiting = waiting_deploy($world);
    $rollback = waiting_deploy($world, Trigger::Rollback);

    expect($waiting->status)->toBe(DeploymentStatus::Waiting)
        ->and($rollback->id)->not->toBe($waiting->id)
        ->and($rollback->status)->toBe(DeploymentStatus::Queued);

    waiting_ready($world, 0);
    expect($waiting->refresh()->status)->toBe(DeploymentStatus::Building)
        ->and($rollback->refresh()->status)->toBe(DeploymentStatus::Queued);
});

it('cancels a waiting deployment when its site is deleted', function () {
    $world = deploy_world();
    waiting_targets($world, [TargetStatus::Provisioning]);
    $waiting = waiting_deploy($world);

    SiteDeleted::dispatch($world->site->id, $world->organization->id, $world->site->slug, $world->serverIds());

    expect($waiting->refresh()->status)->toBe(DeploymentStatus::Cancelled);
});
