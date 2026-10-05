<?php

use Illuminate\Support\Str;
use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Infrastructure\AgentBinaries;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Servers\Domain\Models\Server;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

beforeEach(function () {
    $dir = sys_get_temp_dir().'/falak-agent-bin-'.Str::random(8);
    mkdir($dir);
    file_put_contents("{$dir}/falak-agent-linux-amd64", 'agent v1.1.0');
    file_put_contents("{$dir}/falak-agent-linux-amd64.version", 'v1.1.0');
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test', 'fleet.agent.binaries_path' => $dir, 'fleet.panel_url' => 'https://falak.example.com']);
    app()->forgetInstance(AgentBinaries::class);

    [$this->owner, $this->organization] = memberOf();
    $this->server = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-1']);
    fleet_enroll($this->organization->id, $this->server->id, ['agent_version' => 'v1.0.0', 'agent_sha256' => str_repeat('a', 64)]);
});

it('shows the agent version and that an update is available', function () {
    $this->actingAs($this->owner)->get('/servers')->assertOk()->assertInertia(fn ($page) => $page
        ->where('servers.0.agent.version', 'v1.0.0')
        ->where('servers.0.agent.available_version', 'v1.1.0')
        ->where('servers.0.agent.update_available', true)
        ->where('can.upgrade_agents', true));

    $this->actingAs($this->owner)->get("/servers/{$this->server->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('server.agent.update_available', true)
        ->where('can.upgrade_agent', true));
});

it('upgrades one agent or every outdated agent from the Servers pages (admins only)', function () {
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer)->post("/servers/{$this->server->id}/agent/upgrade")->assertForbidden();
    $this->actingAs($developer)->post('/servers/agents/upgrade')->assertForbidden();

    $this->actingAs($this->owner)->post("/servers/{$this->server->id}/agent/upgrade")->assertRedirect()->assertSessionHas('success');
    expect(Command::query()->where('server_id', $this->server->id)->where('type', 'system.upgrade_agent')->count())->toBe(1)
        ->and(AuditEntry::query()->where('action', 'server.agent_upgrade')->exists())->toBeTrue();

    // Already upgrading: nothing new is queued for the rollout.
    $this->actingAs($this->owner)->post('/servers/agents/upgrade')->assertRedirect()->assertSessionHas('success', 'Every connected agent already runs the latest build.');

    $this->actingAs($this->owner)->get('/servers')->assertInertia(fn ($page) => $page->where('servers.0.agent.upgrade.status', 'running'));
});

it('upgrades an agent through the API', function () {
    $token = app(CreateApiToken::class)($this->owner, $this->organization->id, 'cli', ['fleet.agents.manage', 'servers.view'])->plainTextToken;

    $this->withToken($token)->postJson("/api/v1/servers/{$this->server->id}/agent/upgrade")->assertStatus(202)
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.from_version', 'v1.0.0')
        ->assertJsonPath('data.to_version', 'v1.1.0');

    $this->withToken($token)->getJson("/api/v1/servers/{$this->server->id}")->assertOk()
        ->assertJsonPath('data.agent.update_available', true)
        ->assertJsonPath('data.agent.upgrade.status', 'running');

    app('auth')->forgetGuards();
    $viewer = app(CreateApiToken::class)($this->owner, $this->organization->id, 'ro', ['servers.view'])->plainTextToken;
    $this->withToken($viewer)->postJson("/api/v1/servers/{$this->server->id}/agent/upgrade")->assertForbidden();

    app('auth')->forgetGuards();
    $other = Server::factory()->create(['organization_id' => $this->organization->id]);
    $this->withToken($token)->postJson("/api/v1/servers/{$other->id}/agent/upgrade")->assertStatus(409)->assertJsonPath('message', 'This server has no agent.');
});

it('upgrades only the selected agents of the organization', function () {
    $second = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-2']);
    fleet_enroll($this->organization->id, $second->id, ['agent_version' => 'v1.0.0', 'agent_sha256' => str_repeat('b', 64)]);
    [, $other] = memberOf();
    $foreign = Server::factory()->create(['organization_id' => $other->id, 'name' => 'theirs']);
    fleet_enroll($other->id, $foreign->id, ['agent_version' => 'v1.0.0', 'agent_sha256' => str_repeat('c', 64)]);

    $this->actingAs($this->owner)->post('/servers/agents/upgrade', ['server_ids' => [$second->id, $foreign->id]])
        ->assertRedirect()->assertSessionHas('success');

    $upgraded = Command::query()->where('type', 'system.upgrade_agent')->pluck('server_id')->all();
    expect($upgraded)->toBe([$second->id]);

    $this->actingAs($this->owner)->post('/servers/agents/upgrade', ['server_ids' => [$foreign->id]])
        ->assertRedirect()->assertSessionHas('success', 'The selected agents are offline, already upgrading or up to date.');
    $this->actingAs($this->owner)->post('/servers/agents/upgrade', ['server_ids' => ['nope']])->assertSessionHasErrors('server_ids.0');
});
