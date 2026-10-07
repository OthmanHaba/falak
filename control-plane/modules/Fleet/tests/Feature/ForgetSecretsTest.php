<?php

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Domain\Models\Command;
use Illuminate\Support\Facades\DB;
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

it('forgets payload secrets only once the command is terminal', function () {
    $key = base64_encode(random_bytes(32));
    $handle = $this->gateway->dispatch($this->serverId, 'system.exec', ['script' => 'true', 'env' => ['BACKUP_KEY' => $key], 'mask' => ['BACKUP_KEY']]);

    // Still deliverable: kept.
    expect($this->gateway->forgetSecrets($handle, ['env.BACKUP_KEY']))->toBeFalse()
        ->and(Command::query()->findOrFail($handle->id)->payload)->toContain($key);

    $this->getJson('/agent/v1/commands?wait=0', $this->headers);
    $this->call('POST', "/agent/v1/commands/{$handle->id}/events", [], [], [], $this->transformHeadersToServerVars($this->headers), fleet_ndjson([
        ['command_id' => $handle->id, 'seq' => 0, 'kind' => 'finished', 'exit_code' => 0, 'at' => now()->toIso8601ZuluString()],
    ]))->assertNoContent();

    expect($this->gateway->forgetSecrets($handle, ['env.BACKUP_KEY', 'not.there']))->toBeTrue();
    $payload = json_decode(Command::query()->findOrFail($handle->id)->payload, true);
    expect($payload['env'])->toBe(['BACKUP_KEY' => '[forgotten]'])
        ->and($payload['script'])->toBe('true')
        ->and(json_encode(DB::table('fleet_commands')->get()))->not->toContain($key);
});
