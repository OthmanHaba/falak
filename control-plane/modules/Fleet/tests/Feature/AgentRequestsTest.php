<?php

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\AgentRequests;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Fleet\Contracts\Exceptions\AgentRequestRefused;
use Falak\Fleet\Domain\Models\Command;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

final class EchoAgentRequest implements AgentRequestHandler
{
    public function handle(AgentCaller $caller, array $body): array
    {
        if (($body['gaps'][0]['detail'] ?? '') === 'refuse') {
            throw new AgentRequestRefused('nope', 'Refused.');
        }

        return ['agent' => $caller->agentId, 'server' => $caller->serverId, 'organization' => $caller->organizationId, 'body' => $body];
    }
}

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [, $this->organization] = memberOf();
    $this->serverId = (string) Str::ulid();
    $this->enrolled = fleet_enroll($this->organization->id, $this->serverId);
    $this->headers = fleet_mtls($this->enrolled['fingerprint']);
    // A type with a schema (pitr.gap's), answered here by a test handler.
    app(AgentRequests::class)->register('pitr.gap', EchoAgentRequest::class);
    $this->body = fn (string $detail = 'x') => ['instance' => strtolower((string) Str::ulid()), 'kind' => 'binlog', 'gaps' => [['kind' => 'missing', 'detail' => $detail]]];
});

it('hands an authenticated agent request to the module that registered its type, with the caller', function () {
    $body = ($this->body)();
    $this->postJson('/agent/v1/requests/pitr.gap', $body, $this->headers)
        ->assertOk()
        ->assertJson(['agent' => $this->enrolled['agent']->id, 'server' => $this->serverId, 'organization' => $this->organization->id, 'body' => $body]);

    $this->postJson('/agent/v1/requests/pitr.gap', ($this->body)('refuse'), $this->headers)->assertStatus(409)->assertJson(['error' => 'nope']);
    $this->postJson('/agent/v1/requests/test.nobody', [], $this->headers)->assertNotFound()->assertJson(['error' => 'unknown_request']);
    // Fail closed: a registered type without a schema is never answered.
    app(AgentRequests::class)->register('test.echo', EchoAgentRequest::class);
    $this->postJson('/agent/v1/requests/test.echo', ['x' => 1], $this->headers)->assertNotFound()->assertJson(['error' => 'unknown_request']);
    // mTLS like every agent endpoint.
    $this->postJson('/agent/v1/requests/pitr.gap', $body)->assertUnauthorized();
});

it('throttles each agent\'s requests per type', function () {
    config(['fleet.agent_requests_per_minute' => 2]);
    $this->postJson('/agent/v1/requests/pitr.gap', ($this->body)(), $this->headers)->assertOk();
    $this->postJson('/agent/v1/requests/pitr.gap', ($this->body)(), $this->headers)->assertOk();
    $this->postJson('/agent/v1/requests/pitr.gap', ($this->body)(), $this->headers)->assertStatus(429);
    // Another agent has its own budget.
    $other = fleet_enroll($this->organization->id, (string) Str::ulid());
    $this->postJson('/agent/v1/requests/pitr.gap', ($this->body)(), fleet_mtls($other['fingerprint']))->assertOk();
});

it('validates requests that have a schema', function () {
    // pitr.upload_urls (Databases): an instance id and a batch of named files.
    $this->postJson('/agent/v1/requests/pitr.upload_urls', ['instance' => '../etc', 'kind' => 'wal', 'segments' => []], $this->headers)
        ->assertStatus(422);
});

it('forgets secrets under every element of a list (segments.*.encryption.key)', function () {
    $gateway = app(AgentGateway::class);
    $key = base64_encode(random_bytes(32));
    $handle = $gateway->dispatch($this->serverId, 'system.exec', ['script' => 'true', 'env' => ['A' => $key, 'B' => $key], 'mask' => ['A', 'B']]);
    Command::query()->whereKey($handle->id)->update(['status' => CommandStatus::Succeeded->value, 'finished_at' => now()]);

    expect($gateway->forgetSecrets($handle, ['env.*', 'nothing.*.here']))->toBeTrue();
    expect(json_decode(Command::query()->findOrFail($handle->id)->payload, true)['env'])->toBe(['A' => '[forgotten]', 'B' => '[forgotten]']);
});
