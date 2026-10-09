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
        if (($body['refuse'] ?? false) === true) {
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
    app(AgentRequests::class)->register('test.echo', EchoAgentRequest::class);
});

it('hands an authenticated agent request to the module that registered its type, with the caller', function () {
    $this->postJson('/agent/v1/requests/test.echo', ['x' => 1], $this->headers)
        ->assertOk()
        ->assertJson(['agent' => $this->enrolled['agent']->id, 'server' => $this->serverId, 'organization' => $this->organization->id, 'body' => ['x' => 1]]);

    $this->postJson('/agent/v1/requests/test.echo', ['refuse' => true], $this->headers)->assertStatus(409)->assertJson(['error' => 'nope']);
    $this->postJson('/agent/v1/requests/test.nobody', [], $this->headers)->assertNotFound()->assertJson(['error' => 'unknown_request']);
    // mTLS like every agent endpoint.
    $this->postJson('/agent/v1/requests/test.echo', ['x' => 1])->assertUnauthorized();
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
