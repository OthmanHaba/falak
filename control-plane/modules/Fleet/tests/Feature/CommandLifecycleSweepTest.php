<?php

use Falak\Fleet\Application\Jobs\SweepFleet;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Contracts\Enrollment;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [, $organization] = memberOf();
    $this->serverId = (string) Str::ulid();
    $this->enrolled = fleet_enroll($organization->id, $this->serverId);
    $this->headers = fleet_mtls($this->enrolled['fingerprint']);
    $this->gateway = app(AgentGateway::class);
});

it('re-queues delivered redeliverable commands that were never started and fails them after max attempts', function () {
    Event::fake([CommandFailed::class]);
    config(['fleet.commands.max_attempts' => 2]);
    $handle = $this->gateway->dispatch($this->serverId, 'cron.apply', ['jobs' => []]);

    $this->getJson('/agent/v1/commands?wait=0', $this->headers)->assertJsonCount(1, 'commands');
    $this->travel(91)->seconds();
    SweepFleet::dispatchSync();
    expect($this->gateway->status($handle)->status)->toBe(CommandStatus::Queued);

    $this->getJson('/agent/v1/commands?wait=0', $this->headers)->assertJsonCount(1, 'commands');
    $this->travel(91)->seconds();
    SweepFleet::dispatchSync();

    expect($this->gateway->status($handle)->status)->toBe(CommandStatus::Failed);
    Event::assertDispatched(CommandFailed::class, fn (CommandFailed $e) => str_contains((string) $e->error, 'did not acknowledge'));
});

it('times out running commands after timeout + grace, and accepts a late result', function () {
    Event::fake([CommandFailed::class, CommandFinished::class]);
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x'], timeout: 60);
    $this->getJson('/agent/v1/commands?wait=0', $this->headers);
    $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $this->transformHeadersToServerVars($this->headers), fleet_ndjson([
        ['command_id' => $handle->id, 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()],
    ]));

    $this->travel(100)->seconds();
    SweepFleet::dispatchSync();
    expect($this->gateway->status($handle)->status)->toBe(CommandStatus::Running);

    $this->travel(30)->seconds();
    SweepFleet::dispatchSync();
    expect($this->gateway->status($handle)->status)->toBe(CommandStatus::TimedOut);
    Event::assertDispatched(CommandFailed::class, fn (CommandFailed $e) => $e->status === 'timed_out');

    $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $this->transformHeadersToServerVars($this->headers), fleet_ndjson([
        ['command_id' => $handle->id, 'seq' => 1, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString()],
    ]))->assertNoContent();

    expect($this->gateway->status($handle)->status)->toBe(CommandStatus::Succeeded);
    Event::assertDispatched(CommandFinished::class);
});

it('expires commands the agent never picks up', function () {
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x']);

    $this->travel(3601)->seconds();
    SweepFleet::dispatchSync();

    expect($this->gateway->status($handle)->status)->toBe(CommandStatus::TimedOut);
});

it('heartbeat running_commands promote delivered commands to running', function () {
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x']);
    $this->getJson('/agent/v1/commands?wait=0', $this->headers);

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(['running_commands' => [$handle->id]]), $this->headers)->assertNoContent();

    expect($this->gateway->status($handle)->status)->toBe(CommandStatus::Running);
});

it('revoking the server cancels pending commands', function () {
    Event::fake([CommandFailed::class]);
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x']);

    app(Enrollment::class)->revokeServer($this->serverId, 'deleted');

    expect($this->gateway->status($handle)->status)->toBe(CommandStatus::Cancelled);
    Event::assertDispatched(CommandFailed::class, fn (CommandFailed $e) => $e->status === 'cancelled');
});
