<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Falak\Fleet\Application\Actions\ClaimCommands;
use Falak\Fleet\Application\Jobs\SweepFleet;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Infrastructure\ProtocolSchemas;

require_once __DIR__.'/../Support/helpers.php';

const SESSION_OLD = 'sess-old-0000000000000001';
const SESSION_NEW = 'sess-new-0000000000000002';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [, $organization] = memberOf();
    $this->serverId = (string) Str::ulid();
    $this->enrolled = fleet_enroll($organization->id, $this->serverId);
    $this->gateway = app(AgentGateway::class);
    $this->headers = fn (?string $session) => fleet_mtls($this->enrolled['fingerprint']) + ($session !== null ? ['X-Falak-Agent-Session' => $session] : []);
    $this->poll = fn (?string $session) => $this->getJson('/agent/v1/commands?wait=0', ($this->headers)($session))->assertOk();
    $this->beat = fn (?string $session, array $overrides = []) => $this->postJson('/agent/v1/heartbeat', fleet_heartbeat($overrides), ($this->headers)($session))->assertNoContent();
    $this->finish = fn (CommandHandle $handle, ?string $session, int $seq = 1) => $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [],
        $this->transformHeadersToServerVars(($this->headers)($session)), fleet_ndjson([
            ['command_id' => $handle->id, 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()],
            ['command_id' => $handle->id, 'seq' => $seq, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString()],
        ]))->assertNoContent();
    // cron.apply is redeliverable (x-falak-redeliverable); system.exec is not.
    $this->idempotent = fn () => $this->gateway->dispatch($this->serverId, 'cron.apply', ['jobs' => []]);
    $this->oneShot = fn () => $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x']);
    $this->status = fn (CommandHandle $handle) => $this->gateway->status($handle)->status;
});

it('reads redeliverable command types from the schema catalogue', function () {
    $schemas = app(ProtocolSchemas::class);

    foreach (['edge.caddy.apply', 'telemetry.configure', 'proc.apply', 'cron.apply', 'net.firewall.apply', 'db.user.apply', 'system.upgrade_agent'] as $type) {
        expect($schemas->isRedeliverable($type))->toBeTrue("{$type} should be redeliverable");
    }

    foreach (['deploy.activate', 'deploy.hook', 'system.exec', 'db.create', 'db.restore', 'docker.run', 'no.such_type'] as $type) {
        expect($schemas->isRedeliverable($type))->toBeFalse("{$type} must not be redeliverable");
    }
});

it('records the delivering session on claimed commands', function () {
    $handle = ($this->idempotent)();

    ($this->poll)(SESSION_OLD)->assertJsonCount(1, 'commands');

    expect(Command::query()->find($handle->id)->delivered_session)->toBe(SESSION_OLD)
        ->and(Agent::query()->find($this->enrolled['agent']->id)->session_id)->toBe(SESSION_OLD);
});

it('requeues a redeliverable command whose lease expired, and fails other types fast', function () {
    Event::fake([CommandFailed::class]);
    $apply = ($this->idempotent)();
    $exec = ($this->oneShot)();
    ($this->poll)(SESSION_OLD)->assertJsonCount(2, 'commands');

    $this->travel(60)->seconds();
    SweepFleet::dispatchSync();
    expect(($this->status)($apply))->toBe(CommandStatus::Delivered)
        ->and(($this->status)($exec))->toBe(CommandStatus::Delivered);

    $this->travel(31)->seconds();
    SweepFleet::dispatchSync();

    expect(($this->status)($apply))->toBe(CommandStatus::Queued)
        ->and(($this->status)($exec))->toBe(CommandStatus::TimedOut);
    Event::assertDispatched(CommandFailed::class, fn (CommandFailed $e) => $e->commandId === $exec->id
        && str_contains((string) $e->error, 'did not start the command within 90s')
        && str_contains((string) $e->error, 'system.exec is not safe to run twice'));

    ($this->poll)(SESSION_OLD)->assertJsonCount(1, 'commands')->assertJsonPath('commands.0.id', $apply->id);
});

it('uses the configured lease', function () {
    config(['fleet.commands.lease_seconds' => 30]);
    $apply = ($this->idempotent)();
    ($this->poll)(SESSION_OLD);

    $this->travel(31)->seconds();
    SweepFleet::dispatchSync();

    expect(($this->status)($apply))->toBe(CommandStatus::Queued);
});

it('fails a redeliverable command after max_attempts lost deliveries', function () {
    Event::fake([CommandFailed::class]);
    config(['fleet.commands.max_attempts' => 2]);
    $apply = ($this->idempotent)();

    foreach ([1, 2] as $attempt) {
        ($this->poll)(SESSION_OLD)->assertJsonCount(1, 'commands');
        $this->travel(91)->seconds();
        SweepFleet::dispatchSync();
    }

    expect(($this->status)($apply))->toBe(CommandStatus::Failed);
    Event::assertDispatched(CommandFailed::class, fn (CommandFailed $e) => str_contains((string) $e->error, 'did not acknowledge the command after 2 deliveries'));
});

it('keeps a command the heartbeat reports as running past the lease', function () {
    $apply = ($this->idempotent)();
    ($this->poll)(SESSION_OLD);

    ($this->beat)(SESSION_OLD, ['running_commands' => [$apply->id]]);
    $this->travel(120)->seconds();
    ($this->beat)(SESSION_OLD, ['running_commands' => [$apply->id]]);
    SweepFleet::dispatchSync();

    expect(($this->status)($apply))->toBe(CommandStatus::Running);
});

it('redelivers or fails commands delivered to a previous agent process as soon as a new session appears', function () {
    Event::fake([CommandFailed::class]);
    $apply = ($this->idempotent)();
    $exec = ($this->oneShot)();
    ($this->poll)(SESSION_OLD)->assertJsonCount(2, 'commands');

    // The agent restarted (no lease wait): its first heartbeat carries a new session.
    ($this->beat)(SESSION_NEW);

    expect(($this->status)($apply))->toBe(CommandStatus::Queued)
        ->and(($this->status)($exec))->toBe(CommandStatus::Failed);
    Event::assertDispatched(CommandFailed::class, fn (CommandFailed $e) => $e->commandId === $exec->id
        && $e->status === 'failed'
        && str_contains((string) $e->error, 'The agent restarted before running the command'));

    ($this->poll)(SESSION_NEW)->assertJsonCount(1, 'commands')->assertJsonPath('commands.0.id', $apply->id);
    expect(Command::query()->find($apply->id))
        ->delivered_session->toBe(SESSION_NEW)
        ->attempts->toBe(2);
});

it('recovers commands a pre-session agent received just before it restarted (the upgrade incident)', function () {
    // An agent without session support (0.2.x) long-polls; the server hands it two edge applies right before the
    // process exits (the abandoned long-poll is answered after the upgrade restart).
    $first = $this->gateway->dispatch($this->serverId, 'cron.apply', ['jobs' => []], idempotencyKey: 'a');
    $second = $this->gateway->dispatch($this->serverId, 'cron.apply', ['jobs' => []], idempotencyKey: 'b');
    ($this->poll)(null)->assertJsonCount(2, 'commands');

    // The upgraded agent's first poll switches the session and gets both again in the same response.
    ($this->poll)(SESSION_NEW)->assertJsonCount(2, 'commands');

    expect(($this->status)($first))->toBe(CommandStatus::Delivered)
        ->and(Command::query()->find($second->id)->delivered_session)->toBe(SESSION_NEW);
});

it('does not let a long-poll left behind by the previous process claim commands for the new one', function () {
    $agent = Agent::query()->findOrFail($this->enrolled['agent']->id);
    ($this->poll)(SESSION_OLD); // the old process's long-poll starts (observed session = old)

    // The new process reports the new version; listeners queue re-applies (AgentVersionChanged).
    $reapply = null;
    Event::listen(AgentVersionChanged::class, function () use (&$reapply) {
        $reapply = $this->gateway->dispatch($this->serverId, 'cron.apply', ['jobs' => []]);
    });
    ($this->beat)(SESSION_NEW, ['facts' => fleet_facts(['agent_version' => '1.1.0'])]);
    expect($reapply)->toBeInstanceOf(CommandHandle::class);

    // The old long-poll request is still waiting on the server: its next check claims nothing.
    expect(app(ClaimCommands::class)($agent, SESSION_OLD))->toBe([])
        ->and(($this->status)($reapply))->toBe(CommandStatus::Queued);

    expect(collect(($this->poll)(SESSION_NEW)->json('commands'))->pluck('id'))->toContain($reapply->id);
});

it('redelivers a redeliverable command that was running under the previous process', function () {
    $apply = ($this->idempotent)();
    ($this->poll)(SESSION_OLD);
    ($this->beat)(SESSION_OLD, ['running_commands' => [$apply->id]]);

    ($this->beat)(SESSION_NEW);

    expect(($this->status)($apply))->toBe(CommandStatus::Queued);
});

it('times out a non-redeliverable command interrupted by a restart, and still accepts its late result', function () {
    Event::fake([CommandFailed::class]);
    $exec = ($this->oneShot)();
    ($this->poll)(SESSION_OLD);
    ($this->beat)(SESSION_OLD, ['running_commands' => [$exec->id]]);

    ($this->beat)(SESSION_NEW);

    expect(($this->status)($exec))->toBe(CommandStatus::TimedOut);
    Event::assertDispatched(CommandFailed::class, fn (CommandFailed $e) => str_contains((string) $e->error, 'restarted while running'));

    ($this->finish)($exec, SESSION_OLD);
    expect(($this->status)($exec))->toBe(CommandStatus::Succeeded);
});

it('does not run a command twice when the previous process did finish it', function () {
    $apply = ($this->idempotent)();
    ($this->poll)(SESSION_OLD);

    // The new session appears before the old process's result arrives: the command is queued again...
    ($this->beat)(SESSION_NEW);
    expect(($this->status)($apply))->toBe(CommandStatus::Queued);

    // ...then the old process's finished event lands: the command is done and no longer delivered.
    ($this->finish)($apply, SESSION_OLD);

    expect(($this->status)($apply))->toBe(CommandStatus::Succeeded);
    ($this->poll)(SESSION_NEW)->assertJsonCount(0, 'commands');
});

it('leaves commands alone for agents without session support', function () {
    $apply = ($this->idempotent)();
    ($this->poll)(null);
    ($this->beat)(null, ['running_commands' => [$apply->id]]);

    expect(($this->status)($apply))->toBe(CommandStatus::Running)
        ->and(Agent::query()->find($this->enrolled['agent']->id)->session_id)->toBeNull();
});

it('rejects a malformed session header', function () {
    $this->getJson('/agent/v1/commands?wait=0', ($this->headers)('bad session!'))->assertUnprocessable();
});
