<?php

use Falak\Fleet\Application\Jobs\SweepFleet;
use Falak\Fleet\Contracts\Enrollment;
use Falak\Identity\Contracts\Role;
use Falak\Providers\Contracts\ProviderGateway;
use Falak\Servers\Application\Actions\DeleteServer;
use Falak\Servers\Application\Jobs\CreateProviderMachine;
use Falak\Servers\Application\ServerStatusUpdater;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Tests\Support\FakeProviderGateway;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    $this->providers = new FakeProviderGateway;
    app()->instance(ProviderGateway::class, $this->providers);
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
});

function provisionedServer(): array
{
    test()->post('/servers', ['name' => 'web-'.Str::lower(Str::random(4)), 'type' => 'web', 'provider' => 'custom']);
    $server = Server::query()->latest('id')->firstOrFail();
    $agent = servers_enroll_agent($server);
    servers_poll($agent['headers']);

    return [$server->refresh(), $agent];
}

it('reconciles a successful provisioning result that arrives after a timeout', function () {
    [$server, $agent] = provisionedServer();
    servers_finish($agent['headers'], $server->provision_command_id);
    expect($server->refresh()->status)->toBe(ServerStatus::Active);

    // Second attempt: started, times out, then succeeds late.
    $this->post("/servers/{$server->id}/reprovision");
    $commandId = $server->refresh()->provision_command_id;
    servers_poll($agent['headers']);
    $this->call('POST', "/agent/v1/commands/{$commandId}/events", [], [], [], $this->transformHeadersToServerVars($agent['headers']), fleet_ndjson([
        ['command_id' => $commandId, 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()],
    ]));
    $this->travel(1800 + 61)->seconds();
    SweepFleet::dispatchSync();
    expect($server->refresh()->status)->toBe(ServerStatus::Error);

    $this->call('POST', "/agent/v1/commands/{$commandId}/events", [], [], [], $this->transformHeadersToServerVars($agent['headers']), fleet_ndjson([
        ['command_id' => $commandId, 'seq' => 1, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString()],
    ]))->assertNoContent();

    expect($server->refresh()->status)->toBe(ServerStatus::Active)->and($server->status_message)->toBeNull();
});

it('settles a PHP install that succeeds after being marked failed by a timeout', function () {
    [$server, $agent] = provisionedServer();
    servers_finish($agent['headers'], $server->provision_command_id);
    servers_poll($agent['headers']);

    $this->post("/servers/{$server->id}/php", ['version' => '8.3']);
    [$envelope] = servers_poll($agent['headers']);
    $this->call('POST', "/agent/v1/commands/{$envelope['id']}/events", [], [], [], $this->transformHeadersToServerVars($agent['headers']), fleet_ndjson([
        ['command_id' => $envelope['id'], 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()],
    ]));
    $this->travel(1200 + 61)->seconds();
    SweepFleet::dispatchSync();
    expect($server->phpVersions()->where('version', '8.3')->value('status'))->toBe(PhpVersionStatus::Failed);

    $this->call('POST', "/agent/v1/commands/{$envelope['id']}/events", [], [], [], $this->transformHeadersToServerVars($agent['headers']), fleet_ndjson([
        ['command_id' => $envelope['id'], 'seq' => 1, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString()],
    ]));

    expect($server->phpVersions()->where('version', '8.3')->value('status'))->toBe(PhpVersionStatus::Installed);
});

it('destroys the machine when the server was deleted while it was being created', function () {
    $credential = $this->providers->addCredential($this->organization->id);
    Queue::fake([CreateProviderMachine::class]);

    $this->post('/servers', ['name' => 'hz-1', 'type' => 'web', 'provider' => 'hetzner', 'credential_id' => $credential->id, 'region' => 'fsn1', 'size' => 'cx22', 'image' => 'ubuntu-24.04']);
    $server = Server::query()->firstOrFail();

    // Deletion runs first (the create job is still in flight at the provider).
    app(DeleteServer::class)($server);
    expect(Server::query()->count())->toBe(0);

    (new CreateProviderMachine($server->id))->handle($this->providers, app(ServerStatusUpdater::class));
    expect($this->providers->created)->toBe([]);

    // Deleted mid-flight: row still there but in `deleting` when the provider call returns.
    $this->post('/servers', ['name' => 'hz-2', 'type' => 'web', 'provider' => 'hetzner', 'credential_id' => $credential->id, 'region' => 'fsn1', 'size' => 'cx22', 'image' => 'ubuntu-24.04']);
    $second = Server::query()->where('name', 'hz-2')->firstOrFail();
    $this->providers->beforeCreate = fn () => $second->forceFill(['status' => ServerStatus::Deleting])->save();

    (new CreateProviderMachine($second->id))->handle($this->providers, app(ServerStatusUpdater::class));

    expect($this->providers->created)->toHaveCount(1)
        ->and($this->providers->destroyed)->toBe(['machine-1'])
        ->and($second->refresh()->provider_server_id)->toBeNull();
});

it('does not retry deletion when the provider credential was removed', function () {
    $credential = $this->providers->addCredential($this->organization->id);
    $this->post('/servers', ['name' => 'hz-1', 'type' => 'web', 'provider' => 'hetzner', 'credential_id' => $credential->id, 'region' => 'fsn1', 'size' => 'cx22', 'image' => 'ubuntu-24.04']);
    $server = Server::query()->firstOrFail();
    unset($this->providers->credentials[$credential->id]);

    $this->delete("/servers/{$server->id}", ['name' => 'hz-1'])->assertRedirect();

    expect($server->refresh()->status)->toBe(ServerStatus::Error)
        ->and($server->status_message)->toContain('credential for this server was removed');

    $this->delete("/servers/{$server->id}", ['name' => 'hz-1', 'destroy_at_provider' => false])->assertRedirect();
    expect(Server::query()->count())->toBe(0);
});

it('turns a missing agent into validation errors and rolls back PHP removal', function () {
    [$server, $agent] = provisionedServer();
    servers_finish($agent['headers'], $server->provision_command_id);
    $this->post("/servers/{$server->id}/php", ['version' => '8.3']);
    [$envelope] = collect(servers_poll($agent['headers']))->where('type', 'runtime.php.install')->values()->all();
    servers_finish($agent['headers'], $envelope['id']);

    app(Enrollment::class)->revokeServer($server->id, 'lost');

    $this->post("/servers/{$server->id}/php", ['version' => '8.2'])->assertSessionHasErrors('version');
    $this->delete("/servers/{$server->id}/php/8.3")->assertSessionHasErrors('server');

    expect($server->phpVersions()->where('version', '8.3')->value('status'))->toBe(PhpVersionStatus::Installed)
        ->and($server->refresh()->status)->toBe(ServerStatus::Active);
});

it('re-syncs SSH keys when the agent of an active server is reinstalled', function () {
    [$server, $agent] = provisionedServer();
    servers_finish($agent['headers'], $server->provision_command_id);
    servers_poll($agent['headers']);

    $this->post("/servers/{$server->id}/install-command")->assertRedirect();
    $reinstalled = servers_enroll_agent($server->refresh());

    $types = collect(servers_poll($reinstalled['headers']))->pluck('type')->all();
    expect($types)->toBe(['system.ssh_key.sync', 'system.ssh_key.sync'])
        ->and($server->refresh()->status)->toBe(ServerStatus::Active);
});

it('only reveals install commands of enrolled servers to agent managers', function () {
    [$server, $agent] = provisionedServer();
    servers_finish($agent['headers'], $server->provision_command_id);
    $this->post("/servers/{$server->id}/install-command");

    [$developer] = memberOf($this->organization, Role::Developer);

    $this->get("/servers/{$server->id}")->assertInertia(fn ($page) => $page->where('server.install_command', fn ($c) => str_starts_with((string) $c, 'curl '))->where('server.can_regenerate_install_command', true));
    $this->actingAs($developer)->get("/servers/{$server->id}")->assertInertia(fn ($page) => $page->where('server.install_command', null)->where('server.can_regenerate_install_command', false));
});
