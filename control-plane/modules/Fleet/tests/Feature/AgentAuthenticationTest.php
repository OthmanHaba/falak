<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Falak\Fleet\Contracts\Enrollment;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [, $organization] = memberOf();
    $this->serverId = (string) Str::ulid();
    $this->enrolled = fleet_enroll($organization->id, $this->serverId);
});

it('rejects requests without a fingerprint', function (string $method, string $uri) {
    $this->json($method, $uri, [], ['Accept' => 'application/json'])->assertUnauthorized();
})->with([
    ['POST', '/agent/v1/heartbeat'],
    ['GET', '/agent/v1/ping'],
    ['GET', '/agent/v1/commands'],
    ['POST', '/agent/v1/renew'],
    ['POST', '/agent/v1/insights'],
    ['POST', '/agent/v1/commands/01JAAAAAAAAAAAAAAAAAAAAAAA/events'],
]);

it('rejects unknown and malformed fingerprints', function () {
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls(str_repeat('a', 64)))->assertUnauthorized()->assertJsonPath('error', 'unknown_certificate');
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls('not-hex'))->assertUnauthorized()->assertJsonPath('error', 'missing_certificate');
});

it('accepts the fingerprint case-insensitively', function () {
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls(strtoupper($this->enrolled['fingerprint'])))->assertNoContent();
});

it('only trusts the fingerprint header from configured proxies', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls($this->enrolled['fingerprint']))
        ->assertUnauthorized();

    config(['fleet.trusted_proxies' => ['198.51.100.0/24']]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls($this->enrolled['fingerprint']))
        ->assertNoContent();
});

it('ignores X-Forwarded-For when deciding whether the peer is trusted', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('/agent/v1/heartbeat', fleet_heartbeat(), [...fleet_mtls($this->enrolled['fingerprint']), 'X-Forwarded-For' => '127.0.0.1'])
        ->assertUnauthorized();
});

it('rejects expired certificates', function () {
    $this->travel(91)->days();

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls($this->enrolled['fingerprint']))->assertUnauthorized()->assertJsonPath('error', 'certificate_expired');
});

it('rejects revoked agents with a reason the agent can tell apart', function () {
    app(Enrollment::class)->revokeServer($this->serverId, 'server deleted');

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls($this->enrolled['fingerprint']))
        ->assertUnauthorized()
        ->assertJsonPath('error', 'agent_revoked')
        ->assertJsonStructure(['message', 'error']);
    $this->getJson('/agent/v1/commands', fleet_mtls($this->enrolled['fingerprint']))->assertUnauthorized()->assertJsonPath('error', 'agent_revoked');
    $this->getJson('/agent/v1/ping', fleet_mtls($this->enrolled['fingerprint']))->assertUnauthorized()->assertJsonPath('error', 'agent_revoked');
});

it('tells a revoked certificate of an active agent apart from a revoked agent', function () {
    $this->enrolled['certificate']->forceFill(['revoked_at' => now()])->save();

    $this->getJson('/agent/v1/ping', fleet_mtls($this->enrolled['fingerprint']))->assertUnauthorized()->assertJsonPath('error', 'certificate_revoked');
});

it('answers ping for an authenticated agent without touching the agent record', function () {
    $agent = $this->enrolled['agent'];
    $before = $agent->fresh()->toArray();

    $this->getJson('/agent/v1/ping', fleet_mtls($this->enrolled['fingerprint']))
        ->assertOk()
        ->assertJsonPath('agent_id', $agent->id)
        ->assertJsonStructure(['agent_id', 'time']);

    expect(Arr::except($agent->fresh()->toArray(), ['updated_at']))->toEqual(Arr::except($before, ['updated_at']));
    $this->getJson('/agent/v1/ping', ['Accept' => 'application/json'])->assertUnauthorized()->assertJsonPath('error', 'missing_certificate');
});
