<?php

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Http\Channels\CommandChannel;
use Falak\Identity\Contracts\Role;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [$this->user, $this->organization] = memberOf(null, Role::Viewer);
    $this->serverId = (string) Str::ulid();
    $this->enrolled = fleet_enroll($this->organization->id, $this->serverId);
    $this->handle = app(AgentGateway::class)->dispatch($this->serverId, 'system.exec', ['script' => 'echo hi']);
    $this->call('POST', "/agent/v1/commands/{$this->handle->id}/events", [], [], [], $this->transformHeadersToServerVars(fleet_mtls($this->enrolled['fingerprint'])), fleet_ndjson([
        ['command_id' => $this->handle->id, 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()],
        ['command_id' => $this->handle->id, 'seq' => 1, 'kind' => 'output', 'stream' => 'stdout', 'data' => "hi\n", 'at' => now()->toIso8601ZuluString()],
        ['command_id' => $this->handle->id, 'seq' => 2, 'kind' => 'output', 'stream' => 'stderr', 'data' => "warn\n", 'at' => now()->toIso8601ZuluString()],
    ]));
});

it('shows command status and output to organization members', function () {
    $this->actingAs($this->user)->getJson("/fleet/commands/{$this->handle->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.lines.0.data', "hi\n")
        ->assertJsonPath('data.lines.1.stream', 'stderr')
        ->assertJsonPath('data.last_seq', 2)
        ->assertJsonMissingPath('data.payload');

    $this->actingAs($this->user)->getJson("/fleet/commands/{$this->handle->id}?after=1")->assertJsonCount(1, 'data.lines');
});

it('hides commands from other organizations', function () {
    [$outsider] = memberOf();

    $this->actingAs($outsider)->getJson("/fleet/commands/{$this->handle->id}")->assertNotFound();
});

it('requires authentication for command output', function () {
    $this->getJson("/fleet/commands/{$this->handle->id}")->assertUnauthorized();
});

it('authorizes the private broadcast channel per organization', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
        'driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => 'a', 'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http'],
    ]]);
    app()->forgetInstance(BroadcastManager::class);
    app()->forgetInstance(Factory::class);
    Broadcast::clearResolvedInstances();
    Broadcast::channel(CommandChannel::NAME, CommandChannel::class);

    [$outsider] = memberOf();
    $channel = "private-fleet.commands.{$this->handle->id}";

    $this->actingAs($this->user)->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '123.456'])->assertOk();
    $this->actingAs($outsider)->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '123.456'])->assertForbidden();
});

it('never exposes terminal command output through the generic views', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
        'driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => 'a', 'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http'],
    ]]);
    app()->forgetInstance(BroadcastManager::class);
    app()->forgetInstance(Factory::class);
    Broadcast::clearResolvedInstances();
    Broadcast::channel(CommandChannel::NAME, CommandChannel::class);

    [$admin] = memberOf($this->organization, Role::Admin);
    $terminal = app(AgentGateway::class)->dispatch($this->serverId, 'terminal.open', [
        'session_id' => '01J9ZT8K3M4N5P6Q7R8S9T0V1W', 'user' => 'root', 'shell' => '/bin/bash', 'cols' => 80, 'rows' => 24,
    ]);

    foreach ([$this->user, $admin] as $member) {
        $this->actingAs($member)->getJson("/fleet/commands/{$terminal->id}")->assertNotFound();
        $this->actingAs($member)->post('/broadcasting/auth', ['channel_name' => "private-fleet.commands.{$terminal->id}", 'socket_id' => '123.456'])->assertForbidden();
    }

    $this->actingAs($this->user)->getJson("/fleet/commands/{$this->handle->id}")->assertOk();
});
