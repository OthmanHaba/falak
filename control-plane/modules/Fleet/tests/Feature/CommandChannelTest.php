<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Fleet\Contracts\Exceptions\CommandTimedOut;
use Kiln\Fleet\Contracts\Exceptions\InvalidCommandPayload;
use Kiln\Fleet\Contracts\Exceptions\UnknownCommandType;
use Kiln\Fleet\Domain\Models\Command;
use Kiln\Fleet\Domain\Models\CommandEvent;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Fleet\Events\CommandOutputReceived;
use Kiln\Fleet\Events\InsightsReceived;
use Kiln\Fleet\Infrastructure\Signals\CommandSignal;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/kiln-ca-test']);
    [, $this->organization] = memberOf();
    $this->serverId = (string) Str::ulid();
    $this->enrolled = fleet_enroll($this->organization->id, $this->serverId);
    $this->headers = fleet_mtls($this->enrolled['fingerprint']);
    $this->gateway = app(AgentGateway::class);
});

function fleet_event(string $commandId, int $seq, string $kind, array $extra = []): array
{
    return ['command_id' => $commandId, 'seq' => $seq, 'kind' => $kind, 'at' => now()->toIso8601ZuluString(), ...$extra];
}

it('delivers queued commands as schema-valid envelopes exactly once', function () {
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'uptime'], timeout: 120);

    $response = $this->getJson('/agent/v1/commands?wait=0', $this->headers)->assertOk();

    expect($response->json('commands'))->toHaveCount(1);
    $envelope = $response->json('commands.0');
    expect(fleet_schema_errors('envelope.schema.json', $envelope))->toBe([])
        ->and($envelope)->toBe([
            'id' => $handle->id,
            'type' => 'system.exec',
            'timeout_s' => 120,
            'idempotency_key' => $handle->id,
            'payload' => ['script' => 'uptime'],
        ]);

    expect(Command::query()->find($handle->id)->status)->toBe(CommandStatus::Delivered)
        ->and($this->getJson('/agent/v1/commands?wait=0', $this->headers)->json('commands'))->toBe([]);
});

it('serializes empty payloads as JSON objects', function () {
    $this->gateway->dispatch($this->serverId, 'system.facts', []);

    $raw = $this->getJson('/agent/v1/commands?wait=0', $this->headers)->getContent();

    expect($raw)->toContain('"payload":{}');
});

it('returns immediately with an empty list when nothing is queued and wait=0', function () {
    $this->getJson('/agent/v1/commands?wait=0', $this->headers)->assertOk()->assertExactJson(['commands' => []]);
});

it('long-poll returns early when a command is already queued', function () {
    $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'true']);

    $started = microtime(true);
    $this->getJson('/agent/v1/commands?wait=30', $this->headers)->assertOk()->assertJsonCount(1, 'commands');

    expect(microtime(true) - $started)->toBeLessThan(2);
});

it('only delivers commands of the authenticated agent', function () {
    $other = fleet_enroll($this->organization->id, (string) Str::ulid());
    $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'true']);

    $this->getJson('/agent/v1/commands?wait=0', fleet_mtls($other['fingerprint']))->assertExactJson(['commands' => []]);
});

it('validates payloads against the command schema before dispatch', function () {
    expect(fn () => $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x', 'bogus' => true]))
        ->toThrow(InvalidCommandPayload::class)
        ->and(fn () => $this->gateway->dispatch($this->serverId, 'system.ssh_key.sync', ['user' => 'root', 'keys' => [['id' => '1', 'public_key' => 'nope']]]))
        ->toThrow(InvalidCommandPayload::class, '/keys/0/public_key')
        ->and(fn () => $this->gateway->dispatch($this->serverId, 'system.does_not_exist', []))
        ->toThrow(UnknownCommandType::class)
        ->and(fn () => $this->gateway->dispatch((string) Str::ulid(), 'system.exec', ['script' => 'x']))
        ->toThrow(AgentUnavailable::class);

    expect(Command::query()->count())->toBe(0)
        ->and($this->gateway->supports('provision.apply'))->toBeTrue()
        ->and($this->gateway->supports('../etc/passwd'))->toBeFalse();
});

it('deduplicates pending dispatches by idempotency key', function () {
    $a = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x'], idempotencyKey: 'deploy:1');
    $b = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x'], idempotencyKey: 'deploy:1');

    expect($b->id)->toBe($a->id)->and(Command::query()->count())->toBe(1);
});

it('encrypts payloads at rest', function () {
    $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'echo s3cr3t-value']);

    expect(DB::table('fleet_commands')->value('payload'))->not->toContain('s3cr3t-value');
});

it('ingests NDJSON events idempotently and completes the command', function () {
    Event::fake([CommandFinished::class, CommandFailed::class, CommandOutputReceived::class]);
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'echo hi']);
    $this->getJson('/agent/v1/commands?wait=0', $this->headers);

    $events = [
        fleet_event($handle->id, 0, 'started'),
        fleet_event($handle->id, 1, 'output', ['stream' => 'stdout', 'data' => "hi\n"]),
        fleet_event($handle->id, 2, 'progress', ['progress' => 0.5]),
    ];
    foreach ($events as $event) {
        expect(fleet_schema_errors('event.schema.json', $event))->toBe([]);
    }

    $post = fn (array $batch) => $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $this->transformHeadersToServerVars([...$this->headers, 'Content-Type' => 'application/x-ndjson']), fleet_ndjson($batch));

    $post($events)->assertNoContent();
    expect($this->gateway->status($handle)->status)->toBe(CommandStatus::Running);

    // Retried batch (agent did not see our 204) + the finish event.
    $post([...$events, fleet_event($handle->id, 3, 'finished', ['exit_code' => 0, 'result' => ['ok' => true]])])->assertNoContent();

    expect(CommandEvent::query()->where('command_id', $handle->id)->count())->toBe(4);

    $result = $this->gateway->status($handle);
    expect($result->status)->toBe(CommandStatus::Succeeded)
        ->and($result->exitCode)->toBe(0)
        ->and($result->result)->toBe(['ok' => true])
        ->and($this->gateway->output($handle)->text())->toBe("hi\n")
        ->and($this->gateway->await($handle, 1)->isSuccessful())->toBeTrue();

    Event::assertDispatchedTimes(CommandFinished::class, 1);
    Event::assertDispatched(CommandFinished::class, fn (CommandFinished $e) => $e->commandId === $handle->id && $e->serverId === $this->serverId && $e->type === 'system.exec');
    Event::assertNotDispatched(CommandFailed::class);
    Event::assertDispatched(CommandOutputReceived::class, fn (CommandOutputReceived $e) => collect($e->events)->contains('data', "hi\n"));

    // Replaying the finished event does not announce twice.
    $post([fleet_event($handle->id, 3, 'finished', ['exit_code' => 0])])->assertNoContent();
    Event::assertDispatchedTimes(CommandFinished::class, 1);
});

it('marks non-zero exits as failed', function () {
    Event::fake([CommandFailed::class]);
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'false']);

    $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $this->transformHeadersToServerVars($this->headers), fleet_ndjson([
        fleet_event($handle->id, 0, 'started'),
        fleet_event($handle->id, 1, 'finished', ['exit_code' => 2, 'error' => 'exit status 2']),
    ]))->assertNoContent();

    expect($this->gateway->status($handle)->status)->toBe(CommandStatus::Failed);
    Event::assertDispatched(CommandFailed::class, fn (CommandFailed $e) => $e->status === 'failed' && $e->exitCode === 2 && $e->error === 'exit status 2');
});

it('rejects invalid event batches and events for other commands', function () {
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x']);
    $other = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'y']);
    $server = $this->transformHeadersToServerVars($this->headers);

    $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $server, "not json\n")->assertUnprocessable();
    $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $server, fleet_ndjson([fleet_event($handle->id, -1, 'started')]))->assertUnprocessable();
    $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $server, fleet_ndjson([fleet_event($other->id, 0, 'started')]))->assertUnprocessable();

    expect(CommandEvent::query()->count())->toBe(0);
});

it('returns 404 for commands of another agent', function () {
    $foreign = fleet_enroll($this->organization->id, (string) Str::ulid());
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'x']);

    $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $this->transformHeadersToServerVars(fleet_mtls($foreign['fingerprint'])), fleet_ndjson([fleet_event($handle->id, 0, 'started')]))
        ->assertNotFound();
});

it('cancels queued commands but not delivered ones', function () {
    Event::fake([CommandFailed::class]);
    $queued = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'a']);

    expect($this->gateway->cancel($queued))->toBeTrue()
        ->and($this->gateway->status($queued)->status)->toBe(CommandStatus::Cancelled);
    Event::assertDispatched(CommandFailed::class, fn (CommandFailed $e) => $e->status === 'cancelled');

    $delivered = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'b']);
    $this->getJson('/agent/v1/commands?wait=0', $this->headers);
    expect($this->gateway->cancel($delivered))->toBeFalse();
});

it('await() throws when the command does not finish in time', function () {
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'sleep 100']);

    $this->gateway->await($handle, 0);
})->throws(CommandTimedOut::class);

it('forwards insights NDJSON to the Insights module via an event', function () {
    Event::fake([InsightsReceived::class]);
    $body = fleet_ndjson([
        ['kind' => 'exception', 'trace_id' => 't', 'span_id' => 's', 'site_id' => 'x', 'type' => 'RuntimeException', 'message' => 'boom', 'stacktrace' => '', 'handled' => false, 'user_id' => null, 'event_type' => 'request', 'route_or_name' => '/', 'at' => now()->toIso8601ZuluString()],
        ['kind' => 'aggregate', 'site_id' => 'x', 'event_type' => 'request', 'name' => 'GET /', 'count' => 10, 'p50_ms' => 5, 'p95_ms' => 9, 'max_ms' => 12, 'errors' => 0, 'minute' => now()->startOfMinute()->toIso8601ZuluString()],
    ]);

    $this->call('POST', '/agent/v1/insights', [], [], [], $this->transformHeadersToServerVars($this->headers), $body)->assertNoContent();

    Event::assertDispatched(InsightsReceived::class, fn ($e) => count($e->items) === 2 && $e->serverId === $this->serverId);

    $this->call('POST', '/agent/v1/insights', [], [], [], $this->transformHeadersToServerVars($this->headers), fleet_ndjson([['kind' => 'weird']]))->assertUnprocessable();
});

it('accepts cron heartbeats validated against the cron.apply heartbeat schema', function () {
    Event::fake([InsightsReceived::class]);
    $heartbeat = ['kind' => 'cron_heartbeat', 'job' => 'app-schedule', 'status' => 'finished', 'exit_code' => 0, 'duration_ms' => 120, 'schedule' => '* * * * *', 'scheduled_at' => now()->startOfMinute()->toIso8601ZuluString(), 'at' => now()->toIso8601ZuluString()];
    $server = $this->transformHeadersToServerVars($this->headers);

    $this->call('POST', '/agent/v1/insights', [], [], [], $server, fleet_ndjson([$heartbeat]))->assertNoContent();
    Event::assertDispatched(InsightsReceived::class, fn ($e) => $e->items[0]['kind'] === 'cron_heartbeat');

    $this->call('POST', '/agent/v1/insights', [], [], [], $server, fleet_ndjson([[...$heartbeat, 'status' => 'exploded']]))->assertUnprocessable();
    $this->call('POST', '/agent/v1/insights', [], [], [], $server, fleet_ndjson([array_diff_key($heartbeat, ['scheduled_at' => true])]))->assertUnprocessable();
});

it('checks finished results against the command result schema on raw JSON (empty objects stay objects)', function () {
    Log::spy();
    $handle = $this->gateway->dispatch($this->serverId, 'system.facts', []);
    $facts = json_encode(fleet_facts(['runtimes' => new stdClass]));

    $body = json_encode(['command_id' => $handle->id, 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()])."\n"
        .'{"command_id":"'.$handle->id.'","seq":1,"kind":"finished","exit_code":0,"at":"'.now()->toIso8601ZuluString().'","result":'.$facts."}\n";

    $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $this->transformHeadersToServerVars($this->headers), $body)->assertNoContent();
    Log::shouldNotHaveReceived('warning');

    $bad = $this->gateway->dispatch($this->serverId, 'system.ssh_key.sync', ['user' => 'kiln', 'keys' => []]);
    $this->call('POST', "/agent/v1/commands/{$bad->id}/events", [], [], [], $this->transformHeadersToServerVars($this->headers), fleet_ndjson([
        ['command_id' => $bad->id, 'seq' => 0, 'kind' => 'finished', 'exit_code' => 0, 'result' => ['changed' => true], 'at' => now()->toIso8601ZuluString()],
    ]))->assertNoContent();

    Log::shouldHaveReceived('warning')->once();
    expect($this->gateway->status($bad)->isSuccessful())->toBeTrue();
});

it('wakes the agent only after the transaction that queued the command commits', function () {
    $signal = new class implements CommandSignal
    {
        /** @var list<string> */
        public array $notified = [];

        public function notify(string $agentId): void
        {
            $this->notified[] = $agentId;
        }

        public function wait(string $agentId, int $seconds, callable $check): array
        {
            return $check();
        }
    };
    app()->instance(CommandSignal::class, $signal);
    app()->forgetInstance(AgentGateway::class);
    $gateway = app(AgentGateway::class);
    $agentId = $this->enrolled['agent']->id;

    DB::transaction(function () use ($gateway, $signal) {
        $gateway->dispatch($this->serverId, 'system.facts', []);
        expect($signal->notified)->toBe([]);
    });
    expect($signal->notified)->toBe([$agentId]);

    try {
        DB::transaction(function () use ($gateway) {
            $gateway->dispatch($this->serverId, 'system.facts', []);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect($signal->notified)->toBe([$agentId]);

    $gateway->dispatch($this->serverId, 'system.exec', ['script' => 'uptime']);
    expect($signal->notified)->toBe([$agentId, $agentId]);
});
