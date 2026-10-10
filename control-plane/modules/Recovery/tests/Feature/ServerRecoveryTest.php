<?php

use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Database;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Identity\Contracts\Role;
use Falak\Recovery\Application\Jobs\AdvanceServerRecoveries;
use Falak\Recovery\Domain\Models\ServerRecovery;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Volumes/tests/Support/helpers.php';

/** Deploys the recovery triggers: a queued deployment row, settled by the test. */
final class RecoveryFakeDeploys implements DeploymentTrigger
{
    /** @var list<string> */
    public array $sites = [];

    public function deploy(string $siteId, ?string $requestedBy = null, ?string $commit = null, ?string $message = null, ?string $author = null): string
    {
        $this->sites[] = $siteId;

        return projects_deployment(Site::query()->findOrFail($siteId), 'queued', ['commit_message' => $message])->id;
    }
}

beforeEach(function () {
    databases_fake_dns();
    Carbon::setTestNow('2026-10-10 12:00:00');
    $this->agents = FakeAgentGateway::install();
    [$this->admin, $this->organization] = actingAsMember(Role::Admin);
    $this->lost = databases_server($this->organization, attributes: ['name' => 'app-lost']);
    $this->target = databases_server($this->organization, attributes: ['name' => 'app-new', 'ipv4' => '198.51.100.7']);
    PhpVersion::query()->create(['server_id' => $this->target->id, 'version' => '8.4', 'status' => PhpVersionStatus::Installed, 'is_default' => true,
        'ini' => PhpVersion::DEFAULT_INI, 'fpm' => PhpVersion::defaultFpm(4 * 1024 ** 3)]);

    // A PostgreSQL container with a database backed up 3 hours ago, and a Redis one never backed up.
    $this->instance = databases_instance($this->organization, 'postgresql', $this->lost, ['name' => 'main']);
    $this->db = databases_active_db($this->instance, 'shop');
    databases_active_user($this->instance, 'shop', $this->db);
    $this->provider = databases_provider($this->organization);
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id])->assertSessionHasNoErrors();
    $command = $this->agents->last('db.backup');
    $this->agents->succeed($command['handle'], ['size_bytes' => 4000, 'sha256' => hash('sha256', 'dump'), 'location' => 's3://falak-backups/x', 'duration_ms' => 4200,
        'plaintext_sha256' => hash('sha256', 'plain dump'), 'encryption' => $command['payload']['encryption']['mode'], 'key_id' => $command['payload']['encryption']['key_id'],
        'cipher' => 'aes-256-gcm', 'compression' => 'zstd']);
    Backup::query()->update(['finished_at' => now()->subHours(3)]);
    [$this->cache] = databases_service($this->organization, 'redis', 'cache', $this->lost);

    // A site deployed there, and a volume of it without any backup.
    $this->site = processes_site($this->organization->id, [$this->lost], ['slug' => 'shop']);
    projects_deployment($this->site, 'succeeded', ['created_at' => now()->subDay()]);
    $this->volume = volumes_volume($this->organization->id, $this->lost, 'uploads', VolumeKind::Docker);

    $this->deploys = new RecoveryFakeDeploys;
    app()->instance(DeploymentTrigger::class, $this->deploys);
});

afterEach(fn () => Carbon::setTestNow());

function recovery_advance(): void
{
    dispatch_sync(new AdvanceServerRecoveries);
}

/** @return array<string, mixed> */
function recovery_step(ServerRecovery $recovery, string $key): array
{
    return $recovery->refresh()->step($key) ?? [];
}

it('shows a dry run with the data loss per database and changes nothing', function () {
    $plan = $this->postJson("/servers/{$this->lost->id}/recovery/plan", ['target_server_id' => $this->target->id])->assertOk()->json('data');

    expect($plan['lost'])->toMatchArray(['id' => $this->lost->id, 'name' => 'app-lost'])
        ->and($plan['target'])->toMatchArray(['id' => $this->target->id, 'ipv4' => '198.51.100.7'])
        ->and($plan['problems'])->toBe([])
        ->and(collect($plan['sites'])->pluck('name')->all())->toBe(['Shop'])
        ->and(collect($plan['volumes'])->firstWhere('name', 'uploads'))->toMatchArray(['method' => 'none', 'data_loss_seconds' => null]);

    $main = collect($plan['databases'])->firstWhere('name', 'main');
    $cache = collect($plan['databases'])->firstWhere('name', 'cache');
    expect($main['databases'][0])->toMatchArray(['name' => 'shop', 'method' => 'backup', 'data_loss_seconds' => 3 * 3600, 'customer_held' => false])
        ->and($main['worst_loss_seconds'])->toBe(3 * 3600)
        ->and($cache['databases'][0])->toMatchArray(['method' => 'none', 'data_loss_seconds' => null])
        ->and($cache['worst_loss_seconds'])->toBeNull();

    expect($this->instance->refresh()->server_id)->toBe($this->lost->id)
        ->and(SiteTarget::query()->where('site_id', $this->site->id)->pluck('server_id')->all())->toBe([$this->lost->id])
        ->and(ServerRecovery::query()->count())->toBe(0);
    $this->agents->assertNothingDispatched('db.instance.create');

    // A target that isn't ready yet is a problem the plan names, not an error.
    Server::query()->whereKey($this->target->id)->update(['status' => ServerStatus::Provisioning]);
    $plan = $this->postJson("/servers/{$this->lost->id}/recovery/plan", ['target_server_id' => $this->target->id])->json('data');
    expect($plan['problems'][0])->toContain('wait');

    $this->postJson("/servers/{$this->lost->id}/recovery/plan", ['target_server_id' => $this->lost->id])->assertJsonValidationErrors('target_server_id');
});

it('moves the services, relocates and restores the databases, redeploys, and lists the domains to change', function () {
    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'wrong'])->assertSessionHasErrors('confirm');
    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'app-lost'])
        ->assertSessionHasNoErrors()->assertRedirect("/servers/{$this->lost->id}/recovery");
    $recovery = ServerRecovery::query()->firstOrFail();

    // 1. The replacement is active; 2. the site's server is swapped (no deploy yet), preparing the target.
    expect(recovery_step($recovery, 'replacement')['status'])->toBe('succeeded')
        ->and(recovery_step($recovery, 'sites')['items'][0]['message'])->toBe('Preparing the server for it.')
        ->and(recovery_step($recovery, 'sites')['status'])->toBe('running')
        ->and(SiteTarget::query()->where('site_id', $this->site->id)->pluck('server_id')->all())->toBe([$this->target->id])
        ->and($this->deploys->sites)->toBe([]);
    $this->agents->assertNothingDispatched('db.instance.create');

    SiteTarget::query()->where('site_id', $this->site->id)->update(['status' => TargetStatus::Ready]);
    recovery_advance();

    // 3. Databases: the same instances move to the new server (new volume and host port), containers recreated there.
    expect(recovery_step($recovery, 'sites')['status'])->toBe('succeeded')
        ->and(recovery_step($recovery, 'databases')['status'])->toBe('running');
    $instance = $this->instance->refresh();
    expect($instance->server_id)->toBe($this->target->id)->and($instance->status)->toBe(InstanceStatus::Pending)
        ->and($instance->hostname)->toBe("falak-db-{$instance->id}")
        ->and(Volume::query()->find($instance->volume_id)?->server_id)->toBe($this->target->id)
        ->and(Database::query()->find($this->db->id)?->server_id)->toBe($this->target->id);

    foreach ($this->agents->dispatched('db.instance.create', $this->target->id) as $command) {
        $this->agents->succeed($command['handle']);
    }

    foreach ($this->agents->dispatched('db.create', $this->target->id) as $command) {
        $this->agents->succeed($command['handle']);
    }

    expect(Database::query()->find($this->db->id)?->status)->toBe(ResourceStatus::Active);
    recovery_advance();

    // The latest backup is restored into the recreated database; the never-backed-up Redis starts empty.
    $restore = $this->agents->last('db.restore', $this->target->id);
    expect($restore['payload'])->toMatchArray(['instance' => $instance->id, 'database' => 'shop']);
    $this->agents->succeed($restore['handle'], ['bytes' => 100, 'duration_ms' => 50]);
    recovery_advance();

    $databases = recovery_step($recovery, 'databases');
    expect($databases['status'])->toBe('succeeded')
        ->and(collect($databases['items'])->firstWhere('label', 'main')['state'])->toBe('succeeded')
        ->and(collect($databases['items'])->firstWhere('label', 'cache'))->toMatchArray(['state' => 'manual'])
        ->and(collect($databases['items'])->firstWhere('label', 'cache')['message'])->toContain('no restorable backup');

    // 4. The volume has no backup: skipped. 5. Every deployed site is redeployed on the restored data.
    expect(recovery_step($recovery, 'volumes')['items'][0])->toMatchArray(['state' => 'skipped'])
        ->and($this->deploys->sites)->toBe([$this->site->id])
        ->and(recovery_step($recovery, 'deploy')['status'])->toBe('running');

    Deployment::query()->where('site_id', $this->site->id)->where('status', 'queued')->update(['status' => 'succeeded']);
    recovery_advance();

    expect($recovery->refresh()->status)->toBe('succeeded')
        ->and($recovery->finished_at)->not->toBeNull();
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'recovery.server_recovery_started', 'subject_id' => $this->lost->id]);
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'databases.instance_relocated', 'subject_id' => $this->instance->id]);

    $this->get("/servers/{$this->lost->id}/recovery")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Recovery/ServerRecovery', false)
        ->where('recovery.status', 'succeeded')
        ->where('server.name', 'app-lost'));
});

it('stops at a failed step and retries only what failed', function () {
    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'app-lost']);
    $recovery = ServerRecovery::query()->firstOrFail();
    SiteTarget::query()->where('site_id', $this->site->id)->update(['status' => TargetStatus::Ready]);
    recovery_advance();

    $create = collect($this->agents->dispatched('db.instance.create', $this->target->id))->first(fn ($c) => str_contains($c['handle']->idempotencyKey, $this->instance->id));
    $this->agents->fail($create['handle'], 'image pull failed');
    recovery_advance();

    expect($recovery->refresh()->status)->toBe('failed')
        ->and(collect(recovery_step($recovery, 'databases')['items'])->firstWhere('label', 'main'))->toMatchArray(['state' => 'failed'])
        ->and(recovery_step($recovery, 'deploy')['status'])->toBe('pending');

    // The scheduler leaves a failed recovery alone.
    recovery_advance();
    expect($recovery->refresh()->status)->toBe('failed');

    $this->postJson("/recoveries/{$recovery->id}/steps/sites/retry")->assertJsonValidationErrors('step');
    $before = count($this->agents->dispatched('db.instance.create', $this->target->id));
    $this->postJson("/recoveries/{$recovery->id}/steps/databases/retry")->assertOk()->assertJsonPath('data.status', 'running');

    // The container is created again on the same server; the instance did not move twice.
    expect(count($this->agents->dispatched('db.instance.create', $this->target->id)))->toBe($before + 1)
        ->and($this->instance->refresh()->server_id)->toBe($this->target->id)
        ->and(recovery_step($recovery, 'sites')['status'])->toBe('succeeded');
});

it('refuses a second recovery of the same server while one runs', function () {
    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'app-lost']);
    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'app-lost'])->assertSessionHasErrors('server');
    expect(ServerRecovery::query()->count())->toBe(1);
});

it('is for admins of the server\'s organization only', function () {
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);
    $this->get("/servers/{$this->lost->id}/recovery")->assertForbidden();
    $this->postJson("/servers/{$this->lost->id}/recovery/plan", ['target_server_id' => $this->target->id])->assertForbidden();
    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'app-lost'])->assertForbidden();

    $this->actingAs($this->admin);
    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'app-lost']);
    $recovery = ServerRecovery::query()->firstOrFail();

    [$other, $otherOrganization] = actingAsMember(Role::Owner);
    $foreign = databases_server($otherOrganization);
    $this->get("/servers/{$this->lost->id}/recovery")->assertNotFound();
    $this->getJson("/recoveries/{$recovery->id}")->assertNotFound();
    $this->postJson("/recoveries/{$recovery->id}/steps/databases/retry")->assertNotFound();

    // Their own server can't be recovered onto another organization's server.
    $this->postJson("/servers/{$foreign->id}/recovery/plan", ['target_server_id' => $this->target->id])->assertJsonValidationErrors('target_server_id');
});

it('refuses while the lost server\'s agent still reports, and revokes it when the recovery starts', function () {
    $agent = Agent::factory()->create(['server_id' => $this->lost->id, 'organization_id' => $this->organization->id, 'last_heartbeat_at' => now()->subMinutes(3)]);

    $plan = $this->postJson("/servers/{$this->lost->id}/recovery/plan", ['target_server_id' => $this->target->id])->json('data');
    expect($plan['blocking'][0])->toContain('reported 3 min ago')->toContain('not gone');
    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'app-lost'])->assertSessionHasErrors('server');
    expect(ServerRecovery::query()->count())->toBe(0)->and($this->instance->refresh()->server_id)->toBe($this->lost->id);

    // Silent for longer than recovery.lost_after_minutes: it is gone. Its agent can never reconnect afterwards.
    $agent->forceFill(['last_heartbeat_at' => now()->subMinutes(11)])->save();
    expect($this->postJson("/servers/{$this->lost->id}/recovery/plan", ['target_server_id' => $this->target->id])->json('data.blocking'))->toBe([]);
    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'app-lost'])->assertSessionHasNoErrors();
    expect($agent->refresh()->status)->toBe(AgentStatus::Revoked);
});

it('retries a database whose container came up but whose database failed to be created', function () {
    $this->post("/servers/{$this->lost->id}/recovery", ['target_server_id' => $this->target->id, 'confirm' => 'app-lost']);
    $recovery = ServerRecovery::query()->firstOrFail();
    SiteTarget::query()->where('site_id', $this->site->id)->update(['status' => TargetStatus::Ready]);
    recovery_advance();

    foreach ($this->agents->dispatched('db.instance.create', $this->target->id) as $command) {
        $this->agents->succeed($command['handle']);
    }

    $create = collect($this->agents->dispatched('db.create', $this->target->id))->last();
    $this->agents->fail($create['handle'], 'disk full');
    recovery_advance();
    expect($recovery->refresh()->status)->toBe('failed')
        ->and(collect(recovery_step($recovery, 'databases')['items'])->firstWhere('label', 'main'))->toMatchArray(['state' => 'failed', 'phase' => 'create']);

    $before = count($this->agents->dispatched('db.create', $this->target->id));
    $this->postJson("/recoveries/{$recovery->id}/steps/databases/retry")->assertOk();
    $again = $this->agents->dispatched('db.create', $this->target->id);
    expect(count($again))->toBe($before + 1);
    $this->agents->succeed(collect($again)->last()['handle']);
    recovery_advance();

    expect(collect(recovery_step($recovery, 'databases')['items'])->firstWhere('label', 'main'))->toMatchArray(['phase' => 'restore'])
        ->and($this->agents->last('db.restore', $this->target->id)['payload']['database'])->toBe('shop');
});
