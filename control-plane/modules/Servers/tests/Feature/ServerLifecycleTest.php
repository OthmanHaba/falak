<?php

use Illuminate\Support\Facades\Event;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Fleet\Contracts\AgentStatus;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Events\AgentEnrolled;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Contracts\ProviderGateway;
use Kiln\Providers\Contracts\ProviderType;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Enums\PhpVersionStatus;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Models\SshKey;
use Kiln\Servers\Events\ServerCreated;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Servers\Events\ServerProvisioned;
use Kiln\Servers\Tests\Support\FakeProviderGateway;
use phpseclib3\Crypt\EC;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/kiln-ca-test', 'app.url' => 'https://panel.kiln.test']);
    $this->providers = new FakeProviderGateway;
    app()->instance(ProviderGateway::class, $this->providers);
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
});

it('creates a custom server, enrolls its agent, provisions it and syncs SSH keys', function () {
    Event::fake([ServerCreated::class, ServerProvisioned::class]);
    $key = SshKey::query()->create(['organization_id' => $this->organization->id, 'name' => 'laptop', ...collect(SshKey::parse(EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH')))->only('public_key', 'fingerprint')->all()]);

    $this->post('/servers', [
        'name' => 'app-1',
        'type' => 'app',
        'provider' => 'custom',
        'stack' => ['php' => ['runtime' => 'fpm', 'versions' => ['8.3', '8.4'], 'default' => '8.4'], 'node' => '22', 'database' => 'mysql', 'cache' => 'redis', 'docker' => false],
        'ssh_key_ids' => [$key->id],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $server = Server::query()->where('name', 'app-1')->firstOrFail();
    expect($server->status)->toBe(ServerStatus::Creating)
        ->and($server->install_command)->toStartWith('curl -fsSL https://panel.kiln.test/install/')
        ->and($server->phpVersions()->pluck('version')->all())->toBe(['8.3', '8.4'])
        ->and($server->phpVersions()->where('is_default', true)->value('version'))->toBe('8.4');
    Event::assertDispatched(ServerCreated::class);

    // Installer page shows the command to the operator.
    $this->get("/servers/{$server->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Servers/Show', false)
        ->where('server.status', 'creating')
        ->where('server.install_command', $server->install_command)
        ->where('agent', null));

    // Agent enrolls → provision.apply is queued.
    $agent = servers_enroll_agent($server, ['memory_bytes' => 2 * 1024 ** 3, 'public_ipv4' => '203.0.113.50']);
    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Provisioning)
        ->and($server->install_command)->toBeNull()
        ->and($server->ipv4)->toBe('203.0.113.50')
        ->and($server->memory_bytes)->toBe(2 * 1024 ** 3)
        ->and($server->os)->toBe('ubuntu 24.04');

    $envelopes = servers_poll($agent['headers']);
    expect($envelopes)->toHaveCount(1)
        ->and($envelopes[0]['type'])->toBe('provision.apply')
        ->and($envelopes[0]['id'])->toBe($server->provision_command_id)
        ->and($envelopes[0]['idempotency_key'])->toBe("provision:{$server->id}:1")
        ->and(app(ProtocolSchemas::class)->validateCommand('provision.apply', ProtocolSchemas::toJson($envelopes[0]['payload'])))->toBe([])
        ->and($envelopes[0]['payload']['runtimes']['php'])->toMatchArray(['versions' => ['8.3', '8.4'], 'default' => '8.4', 'fpm' => true])
        ->and($envelopes[0]['payload']['apt']['packages'])->toContain('mysql-server', 'redis-server');

    // Agent reports success → active, provisioned, keys synced.
    servers_finish($agent['headers'], $server->provision_command_id);
    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Active)
        ->and($server->provisioned_at)->not->toBeNull()
        ->and($server->phpVersions()->where('status', PhpVersionStatus::Installed)->count())->toBe(2);
    Event::assertDispatched(ServerProvisioned::class, fn (ServerProvisioned $e) => $e->serverId === $server->id);

    $syncs = collect(servers_poll($agent['headers']))->where('type', 'system.ssh_key.sync')->values();
    expect($syncs)->toHaveCount(2)
        ->and($syncs[0]['payload'])->toBe(['user' => 'kiln', 'keys' => [['id' => $key->id, 'name' => 'laptop', 'public_key' => $key->public_key]], 'exclusive' => false])
        ->and($syncs[1]['payload'])->toBe(['user' => 'root', 'keys' => [], 'exclusive' => false]);

    expect(AuditEntry::query()->where('organization_id', $this->organization->id)->pluck('action')->all())
        ->toContain('server.created', 'agent.enrolled', 'server.provisioning_started', 'server.provisioned');
});

it('marks the server errored when provisioning fails and can retry', function () {
    $this->post('/servers', ['name' => 'w1', 'type' => 'web', 'provider' => 'custom'])->assertSessionHasNoErrors();
    $server = Server::query()->firstOrFail();
    $agent = servers_enroll_agent($server);
    servers_poll($agent['headers']);

    servers_finish($agent['headers'], $server->refresh()->provision_command_id, 100, 'apt-get install failed');

    $server->refresh();
    expect($server->status)->toBe(ServerStatus::Error)
        ->and($server->status_message)->toBe('Provisioning failed: apt-get install failed');

    $this->post("/servers/{$server->id}/reprovision")->assertRedirect();
    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Provisioning)
        ->and($server->provision_attempts)->toBe(2)
        ->and(servers_poll($agent['headers'])[0]['idempotency_key'])->toBe("provision:{$server->id}:2");

    servers_finish($agent['headers'], $server->provision_command_id);
    expect($server->refresh()->status)->toBe(ServerStatus::Active);
});

it('creates provider servers with cloud-init that installs the agent', function () {
    $credential = $this->providers->addCredential($this->organization->id);
    $key = SshKey::query()->create(['organization_id' => $this->organization->id, 'name' => 'ops', ...collect(SshKey::parse(EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH')))->only('public_key', 'fingerprint')->all()]);

    $this->post('/servers', [
        'name' => 'hz-1', 'type' => 'web', 'provider' => 'hetzner', 'credential_id' => $credential->id,
        'region' => 'fsn1', 'size' => 'cx22', 'image' => 'ubuntu-24.04', 'ssh_key_ids' => [$key->id],
    ])->assertSessionHasNoErrors();

    $server = Server::query()->firstOrFail();
    $spec = $this->providers->created[0];

    expect($spec->region)->toBe('fsn1')
        ->and($spec->size)->toBe('cx22')
        ->and($spec->image)->toBe('ubuntu-24.04')
        ->and($spec->name)->toBe('hz-1')
        ->and($spec->sshKeyIds)->toBe(['key-1'])
        ->and($spec->labels)->toMatchArray(['kiln-server' => $server->id, 'kiln-type' => 'web'])
        ->and($spec->userData)->toStartWith("#!/bin/sh\n")
        ->and($spec->userData)->toContain($server->install_command);

    expect($server->provider_server_id)->toBe('machine-1')
        ->and($server->ipv4)->toBe('198.51.100.20')
        ->and($server->status)->toBe(ServerStatus::Creating);
});

it('surfaces provider errors on the server', function () {
    $credential = $this->providers->addCredential($this->organization->id);
    $this->providers->failCreate = new ProviderException('server type unavailable in location', 'hetzner', 422);

    $this->post('/servers', ['name' => 'hz-1', 'type' => 'web', 'provider' => 'hetzner', 'credential_id' => $credential->id, 'region' => 'fsn1', 'size' => 'cx22', 'image' => 'ubuntu-24.04']);

    $server = Server::query()->firstOrFail();
    expect($server->status)->toBe(ServerStatus::Error)
        ->and($server->status_message)->toBe('Provider error: server type unavailable in location');
});

it('validates server creation input', function (array $input, string $field) {
    $this->post('/servers', [...['name' => 'x1', 'type' => 'web', 'provider' => 'custom'], ...$input])->assertSessionHasErrors($field);
    expect(Server::query()->count())->toBe(0);
})->with([
    'bad name' => [['name' => '../etc'], 'name'],
    'bad type' => [['type' => 'mainframe'], 'type'],
    'db on cache server' => [['type' => 'cache', 'stack' => ['cache' => 'redis', 'database' => 'mysql']], 'stack.database'],
    'unsupported php' => [['stack' => ['php' => ['runtime' => 'fpm', 'versions' => ['5.6'], 'default' => '5.6']]], 'stack.php.versions'],
    'provider without credential' => [['provider' => 'hetzner'], 'credential_id'],
    'foreign ssh key' => [['ssh_key_ids' => ['01JXXXXXXXXXXXXXXXXXXXXXXX']], 'ssh_key_ids'],
]);

it('rejects credentials of another organization', function () {
    [, $other] = memberOf();
    $credential = $this->providers->addCredential($other->id);

    $this->post('/servers', ['name' => 'x', 'type' => 'web', 'provider' => 'hetzner', 'credential_id' => $credential->id, 'region' => 'a', 'size' => 'b', 'image' => 'c'])
        ->assertSessionHasErrors('credential_id');
});

it('deletes a server: revokes the agent, destroys the machine and removes records', function () {
    Event::fake([ServerDeleted::class]);
    $credential = $this->providers->addCredential($this->organization->id);
    $this->post('/servers', ['name' => 'hz-1', 'type' => 'web', 'provider' => 'hetzner', 'credential_id' => $credential->id, 'region' => 'fsn1', 'size' => 'cx22', 'image' => 'ubuntu-24.04']);
    $server = Server::query()->firstOrFail();
    servers_enroll_agent($server);

    [$admin] = memberOf($this->organization, Role::Admin);
    $this->actingAs($admin)->delete("/servers/{$server->id}", ['name' => 'wrong'])->assertSessionHasErrors('name');
    $this->actingAs($admin)->delete("/servers/{$server->id}", ['name' => 'hz-1'])->assertRedirect('/servers');

    expect(Server::query()->count())->toBe(0)
        ->and($this->providers->destroyed)->toBe(['machine-1'])
        ->and(Agent::query()->first()->status)->toBe(AgentStatus::Revoked);
    Event::assertDispatched(ServerDeleted::class, fn (ServerDeleted $e) => $e->serverId === $server->id);
});

it('keeps the server in error when the provider refuses deletion', function () {
    $credential = $this->providers->addCredential($this->organization->id);
    $this->post('/servers', ['name' => 'hz-1', 'type' => 'web', 'provider' => 'hetzner', 'credential_id' => $credential->id, 'region' => 'fsn1', 'size' => 'cx22', 'image' => 'ubuntu-24.04']);
    $server = Server::query()->firstOrFail();
    $this->providers->failDestroy = new ProviderException('locked', 'hetzner', 423);

    [$admin] = memberOf($this->organization, Role::Admin);

    try {
        $this->actingAs($admin)->delete("/servers/{$server->id}", ['name' => 'hz-1']);
    } catch (ProviderException) {
        // sync queue surfaces the job exception; in production the job retries with backoff.
    }

    expect(Server::query()->find($server->id)?->status)->toBe(ServerStatus::Deleting);
});

it('regenerates the install command and the old one stops working', function () {
    $this->post('/servers', ['name' => 'c1', 'type' => 'web', 'provider' => 'custom']);
    $server = Server::query()->firstOrFail();
    $old = $server->install_command;

    // Re-binding a server to a new host needs fleet.agents.manage (admins), not just servers.manage.
    $this->post("/servers/{$server->id}/install-command")->assertForbidden();

    [$admin] = memberOf($this->organization, Role::Admin);
    $this->actingAs($admin)->post("/servers/{$server->id}/install-command")->assertRedirect();

    expect($server->refresh()->install_command)->not->toBe($old);
    preg_match('#/install/([A-Za-z0-9]+)#', $old, $m);
    $this->get("/install/{$m[1]}")->assertNotFound();
});

it('updates facts from heartbeats and exposes servers through the directory contract', function () {
    $this->post('/servers', ['name' => 'c1', 'type' => 'web', 'provider' => 'custom']);
    $server = Server::query()->firstOrFail();
    $agent = servers_enroll_agent($server);
    servers_finish($agent['headers'], $server->refresh()->provision_command_id);

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(['facts' => fleet_facts(['cpus' => 16, 'arch' => 'arm64'])]), $agent['headers'])->assertNoContent();

    $data = app(ServerDirectory::class)->find($server->id);
    expect($data->type)->toBe(ServerType::Web)
        ->and($data->isActive())->toBeTrue()
        ->and($data->arch)->toBe('arm64')
        ->and($data->phpVersions)->toBe(['8.4'])
        ->and($data->defaultPhpVersion)->toBe('8.4')
        ->and($data->phpRuntime)->toBe('frankenphp')
        ->and($server->refresh()->cpus)->toBe(16)
        ->and(app(ServerDirectory::class)->forOrganization($this->organization->id, [ServerType::Web], activeOnly: true))->toHaveCount(1)
        ->and(app(ServerDirectory::class)->forOrganization($this->organization->id, [ServerType::Database]))->toBe([])
        ->and(app(ServerDirectory::class)->phpSettings($server->id, '8.4')->fpm['pm'])->toBe('dynamic')
        ->and(app(AgentDirectory::class)->forServer($server->id)->metrics['cpu_percent'])->toEqual(12.5);
});

it('cleans up servers when the organization is deleted', function () {
    $this->post('/servers', ['name' => 'c1', 'type' => 'web', 'provider' => 'custom']);

    OrganizationDeleted::dispatch($this->organization->id);

    expect(Server::query()->count())->toBe(0);
});

it('does not advance servers of another organization from foreign enrollments', function () {
    $this->post('/servers', ['name' => 'c1', 'type' => 'web', 'provider' => 'custom']);
    $server = Server::query()->firstOrFail();

    AgentEnrolled::dispatch('01JAGENTXXXXXXXXXXXXXXXXXX', '01JOTHERORGXXXXXXXXXXXXXXX', $server->id, fleet_facts());

    expect($server->refresh()->status)->toBe(ServerStatus::Creating);
});

it('command output is visible for the provisioning log', function () {
    $this->post('/servers', ['name' => 'c1', 'type' => 'web', 'provider' => 'custom']);
    $server = Server::query()->firstOrFail();
    $agent = servers_enroll_agent($server);
    servers_finish($agent['headers'], $server->refresh()->provision_command_id);

    $this->getJson("/fleet/commands/{$server->provision_command_id}")->assertOk()
        ->assertJsonPath('data.status', CommandStatus::Succeeded->value)
        ->assertJsonPath('data.lines.0.data', "ok\n");
});

it('uses the provider type enum values for the fake credential', function () {
    expect($this->providers->addCredential($this->organization->id, ProviderType::DigitalOcean)->provider)->toBe(ProviderType::DigitalOcean);
});
