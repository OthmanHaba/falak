<?php

use Illuminate\Support\Facades\Event;
use Kiln\Alerting\Domain\Models\Alert;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Identity\Application\Actions\CreateApiToken;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\MachineInspection;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\ServerNeedsAttention;
use Kiln\Servers\Events\ServerProvisioned;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/machine_reports.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/kiln-ca-test', 'app.url' => 'https://panel.kiln.test']);
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
});

/**
 * A custom app server (PostgreSQL, Redis, Docker) whose agent enrolls with the given features.
 *
 * @param  list<string>  $features
 * @return array{0: Server, 1: array<string, string>}
 */
function mc_enrolled_server(array $features = ['provision.v2'], array $stack = []): array
{
    test()->post('/servers', [
        'name' => 'app-1', 'type' => 'app', 'provider' => 'custom',
        'stack' => array_replace(['php' => ['runtime' => 'frankenphp', 'versions' => ['8.4'], 'default' => '8.4'], 'node' => '22', 'database' => 'postgresql', 'cache' => 'redis', 'docker' => true], $stack),
    ])->assertSessionHasNoErrors();

    $server = Server::query()->where('name', 'app-1')->firstOrFail();
    $agent = servers_enroll_agent($server, ['features' => $features, 'memory_bytes' => 2 * 1024 ** 3, 'agent_version' => '0.6.0']);

    return [$server->refresh(), $agent['headers']];
}

it('checks the machine first, then applies a plan built from the decisions (the incident machine)', function () {
    Event::fake([ServerProvisioned::class]);
    [$server, $headers] = mc_enrolled_server();

    expect($server->status)->toBe(ServerStatus::Provisioning)
        ->and($server->status_message)->toBe('Checking the machine before provisioning.');

    $envelopes = servers_poll($headers);
    expect($envelopes)->toHaveCount(1)
        ->and($envelopes[0]['type'])->toBe('provision.inspect')
        ->and($envelopes[0]['payload']['packages'])->toBe(config('servers.base_packages'))
        ->and(app(ProtocolSchemas::class)->validateCommand('provision.inspect', ProtocolSchemas::toJson($envelopes[0]['payload'])))->toBe([]);

    $report = mc_docker_ce(mc_report());
    expect(app(ProtocolSchemas::class)->validateCommandResult('provision.inspect', ProtocolSchemas::toJson($report)))->toBe([]);
    servers_finish($headers, $envelopes[0]['id'], result: $report);

    $server->refresh();
    $inspection = $server->machineInspection;
    expect($inspection->status)->toBe(MachineInspection::FINISHED)
        ->and($inspection->blocking)->toBeFalse()
        ->and($inspection->agent_version)->toBe('0.6.0')
        ->and($inspection->report['docker']['engine_package'])->toBe('docker-ce')
        ->and(collect($inspection->decisions)->firstWhere('component', 'docker')['decision'])->toBe('adopt')
        ->and($server->status)->toBe(ServerStatus::Provisioning)
        ->and($server->provision_attempts)->toBe(1);

    $apply = servers_poll($headers);
    expect($apply)->toHaveCount(1)
        ->and($apply[0]['type'])->toBe('provision.apply')
        ->and($apply[0]['id'])->toBe($server->provision_command_id)
        ->and($apply[0]['payload']['apt']['packages'])->not->toContain('docker.io', 'docker-compose-v2', 'docker-buildx')
        ->and(collect($apply[0]['payload']['components'])->firstWhere('name', 'docker')['decision'])->toBe('adopt')
        ->and($apply[0]['payload'])->not->toHaveKey('hostname')
        ->and(app(ProtocolSchemas::class)->validateCommand('provision.apply', ProtocolSchemas::toJson($apply[0]['payload'])))->toBe([]);

    servers_finish($headers, $server->provision_command_id);
    expect($server->refresh()->status)->toBe(ServerStatus::Active);
    expect(AuditEntry::query()->pluck('action')->all())->toContain('server.machine_check_started', 'server.machine_checked', 'server.provisioning_started', 'server.provisioned');
});

it('stops at needs_attention when something blocks, re-checks and provisions once it is fixed', function () {
    Event::fake([ServerNeedsAttention::class]);
    [$server, $headers] = mc_enrolled_server();
    $inspect = servers_poll($headers)[0];

    $nginx = mc_listen(mc_service(mc_package(mc_report(), 'nginx', '1.24.0-2ubuntu7'), 'nginx.service'), 80, 'nginx', 'nginx.service');
    servers_finish($headers, $inspect['id'], result: $nginx);

    $server->refresh();
    expect($server->status)->toBe(ServerStatus::NeedsAttention)
        ->and($server->status_message)->toBe("Machine check: 1 conflict to fix before provisioning. Port 80 is in use by nginx, which Kiln's edge needs.")
        ->and($server->provision_attempts)->toBe(0)
        ->and(servers_poll($headers))->toBe([]);
    Event::assertDispatched(ServerNeedsAttention::class, fn (ServerNeedsAttention $e) => $e->serverId === $server->id && $e->blocks === ["Port 80 is in use by nginx, which Kiln's edge needs."]);

    $this->get("/servers/{$server->id}")->assertInertia(fn ($page) => $page
        ->where('server.status', 'needs_attention')
        ->where('machineCheck.blocking', true)
        ->where('machineCheck.status', 'finished')
        ->where('machineCheck.components.0.component', 'edge')
        ->where('machineCheck.components.0.decision', 'block')
        ->where('machineCheck.components.0.hint', 'Stop and disable it (systemctl disable --now nginx.service) or move it to another port, then re-check.'));

    // Provision is refused while blocked.
    $this->post("/servers/{$server->id}/provision")->assertSessionHasErrors(['server' => $server->status_message]);

    // Re-check after stopping nginx: nothing blocks, the server waits for Provision.
    $this->post("/servers/{$server->id}/inspection")->assertSessionHasNoErrors()->assertRedirect();
    $this->post("/servers/{$server->id}/inspection")->assertSessionHasErrors(['server' => 'A machine check is already running.']);
    $recheck = servers_poll($headers)[0];
    expect($recheck['type'])->toBe('provision.inspect')
        ->and($server->machineInspection()->value('purpose'))->toBe('check');
    servers_finish($headers, $recheck['id'], result: mc_report());

    $server->refresh();
    expect($server->status)->toBe(ServerStatus::NeedsAttention)
        ->and($server->status_message)->toBe('Nothing blocks provisioning any more. Provision to continue.')
        ->and($server->machineInspection->blocking)->toBeFalse()
        ->and(servers_poll($headers))->toBe([]);

    $this->post("/servers/{$server->id}/provision")->assertSessionHasNoErrors();
    $server->refresh();
    expect($server->status)->toBe(ServerStatus::Provisioning)
        ->and(servers_poll($headers)[0]['type'])->toBe('provision.apply');
});

it('raises a needs-attention alert', function () {
    [$server, $headers] = mc_enrolled_server();
    $report = mc_report();
    $report['ssh']['users'] = [['name' => 'root', 'uid' => 0, 'authorized_keys' => 0]];
    servers_finish($headers, servers_poll($headers)[0]['id'], result: $report);

    $alert = Alert::query()->where('type', 'servers.needs_attention')->firstOrFail();
    expect($alert->title)->toBe('Server app-1 needs attention')
        ->and($alert->body)->toContain('Password login would be turned off');
});

it('skips the machine check for agents without provision.v2 and provisions as before', function () {
    [$server, $headers] = mc_enrolled_server(features: ['compose.up.services']);

    $envelopes = servers_poll($headers);
    expect($server->status)->toBe(ServerStatus::Provisioning)
        ->and($envelopes)->toHaveCount(1)
        ->and($envelopes[0]['type'])->toBe('provision.apply')
        ->and($envelopes[0]['payload'])->not->toHaveKey('components')
        ->and($envelopes[0]['payload']['apt']['packages'])->toContain('docker.io', 'docker-compose-v2', 'docker-buildx')
        ->and($server->machineInspection()->exists())->toBeFalse();

    $this->post("/servers/{$server->id}/inspection")->assertSessionHasErrors(['server' => 'The agent on this server cannot run the machine check. Update the agent first.']);
});

it('fails provisioning when the machine check fails, and retries with a new check', function () {
    [$server, $headers] = mc_enrolled_server();
    servers_finish($headers, servers_poll($headers)[0]['id'], 1, 'context deadline exceeded');

    $server->refresh();
    expect($server->status)->toBe(ServerStatus::Error)
        ->and($server->status_message)->toBe('Machine check failed: context deadline exceeded')
        ->and($server->machineInspection->status)->toBe(MachineInspection::FAILED);

    $this->post("/servers/{$server->id}/reprovision")->assertRedirect();
    expect($server->refresh()->status)->toBe(ServerStatus::Provisioning)
        ->and(servers_poll($headers)[0]['type'])->toBe('provision.inspect');
});

it('re-provisions an active server through the machine check', function () {
    [$server, $headers] = mc_enrolled_server();
    servers_finish($headers, servers_poll($headers)[0]['id'], result: mc_report());
    servers_finish($headers, servers_poll($headers)[0]['id']);
    expect($server->refresh()->status)->toBe(ServerStatus::Active);
    servers_poll($headers); // SSH key syncs

    // A re-check on an active server only refreshes the report.
    $this->post("/servers/{$server->id}/inspection")->assertSessionHasNoErrors();
    servers_finish($headers, servers_poll($headers)[0]['id'], result: mc_listen(mc_report(), 80, 'nginx', 'nginx.service'));
    expect($server->refresh()->status)->toBe(ServerStatus::Active)
        ->and($server->machineInspection->blocking)->toBeTrue();

    $this->post("/servers/{$server->id}/reprovision")->assertRedirect();
    expect(servers_poll($headers)[0]['type'])->toBe('provision.inspect');
});

it('refuses a database engine the stored machine check blocks', function () {
    [$server, $headers] = mc_enrolled_server(stack: ['database' => null]);
    servers_finish($headers, servers_poll($headers)[0]['id'], result: mc_package(mc_report(), 'mariadb-server', '1:10.11.8-0ubuntu0.24.04.1'));
    servers_finish($headers, servers_poll($headers)[0]['id']);
    expect($server->refresh()->status)->toBe(ServerStatus::Active);

    $this->post("/servers/{$server->id}/database-engine", ['engine' => 'mysql'])
        ->assertSessionHasErrors(['engine' => 'MariaDB 10.11.8 is installed, but this server is set up for MySQL. '.
            "Kiln won't run two database engines on one machine. Remove MariaDB (apt purge mariadb-server) or use a server set up for MariaDB, then re-check."]);
    expect($server->refresh()->stack->database)->toBeNull()
        ->and($server->engine_command_id)->toBeNull();

    // The engine that is already there is adopted, not reinstalled.
    $this->post("/servers/{$server->id}/database-engine", ['engine' => 'mariadb'])->assertSessionHasNoErrors();
    $plan = collect(servers_poll($headers))->firstWhere('type', 'provision.apply')['payload'];
    expect($plan['apt']['packages'])->not->toContain('mariadb-server')
        ->and(collect($plan['components'])->firstWhere('name', 'database'))->toMatchArray(['decision' => 'adopt', 'packages' => ['mariadb-server'], 'service' => 'mariadb']);
});

it('serves the inspection over the API with the server permissions', function () {
    [$server, $headers] = mc_enrolled_server();
    $token = app(CreateApiToken::class)($this->user, $this->organization->id, 'cli', ['servers.view', 'servers.manage'])->plainTextToken;
    $viewer = app(CreateApiToken::class)($this->user, $this->organization->id, 'ro', ['servers.view'])->plainTextToken;
    // Token requests only: a session user would satisfy Sanctum without the token's abilities.
    $this->app['auth']->forgetGuards();

    $this->withToken($viewer)->getJson("/api/v1/servers/{$server->id}/inspection")->assertOk()
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.purpose', 'provision')
        ->assertJsonPath('data.report', null)
        ->assertJsonPath('data.components', []);

    servers_finish($headers, servers_poll($headers)[0]['id'], result: mc_listen(mc_report(), 6379, 'docker-proxy', 'docker.service', container: true));

    $this->withToken($viewer)->getJson("/api/v1/servers/{$server->id}/inspection")->assertOk()
        ->assertJsonPath('data.status', 'finished')
        ->assertJsonPath('data.supported', true)
        ->assertJsonPath('data.blocking', true)
        ->assertJsonPath('data.components.0.component', 'cache')
        ->assertJsonPath('data.components.0.decision', 'block')
        ->assertJsonPath('data.components.0.decision_label', 'Blocked')
        ->assertJsonPath('data.report.hostname', 'ubuntu-s-1vcpu');

    $this->withToken($viewer)->postJson("/api/v1/servers/{$server->id}/inspection")->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson("/api/v1/servers/{$server->id}/inspection")->assertStatus(202)
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.purpose', 'check')
        ->assertJsonPath('data.blocking', true);
    expect(servers_poll($headers)[0]['type'])->toBe('provision.inspect');

    $other = Server::factory()->create(['organization_id' => $this->organization->id]);
    $this->withToken($token)->getJson("/api/v1/servers/{$other->id}/inspection")->assertNotFound()->assertJsonPath('message', 'This server has no machine check yet.');
    $this->withToken($token)->postJson("/api/v1/servers/{$other->id}/inspection")->assertUnprocessable();

    [$outsider, $elsewhere] = memberOf();
    $foreign = app(CreateApiToken::class)($outsider, $elsewhere->id, 'x', ['servers.view', 'servers.manage'])->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withToken($foreign)->getJson("/api/v1/servers/{$server->id}/inspection")->assertNotFound();
});

it('forbids viewers from re-checking and provisioning', function () {
    $server = Server::factory()->status(ServerStatus::NeedsAttention)->create(['organization_id' => $this->organization->id]);
    [$viewer] = memberOf($this->organization, Role::Viewer);

    $this->actingAs($viewer)->post("/servers/{$server->id}/inspection")->assertForbidden();
    $this->actingAs($viewer)->post("/servers/{$server->id}/provision")->assertForbidden();
    $this->actingAs($viewer)->get("/servers/{$server->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('machineCheck.status', null)
        ->where('machineCheck.supported', false));
});

it('filters the fleet by needs_attention and keeps those servers out of active-only lookups', function () {
    Server::factory()->status(ServerStatus::NeedsAttention)->create(['organization_id' => $this->organization->id, 'name' => 'blocked-1']);
    Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'ok-1']);

    $this->get('/servers?status=needs_attention')->assertOk()->assertInertia(fn ($page) => $page
        ->has('servers', 1)
        ->where('servers.0.name', 'blocked-1')
        ->where('servers.0.status', 'needs_attention'));

    expect(collect(app(ServerDirectory::class)->forOrganization($this->organization->id, activeOnly: true))->pluck('name')->all())->toBe(['ok-1']);
});
