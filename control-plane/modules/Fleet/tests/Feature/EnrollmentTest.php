<?php

use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\Enrollment;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\InstallToken;
use Falak\Fleet\Events\AgentEnrolled;
use Falak\Fleet\Events\AgentRevoked;
use Falak\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Falak\Identity\Domain\Models\AuditEntry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use phpseclib3\File\X509;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test', 'app.url' => 'https://panel.falak.test', 'fleet.otlp_endpoint' => 'https://otlp.falak.test']);
    [, $this->organization] = memberOf();
    $this->serverId = (string) Str::ulid();
});

it('enrolls an agent with a one-time token and returns a schema-valid response', function () {
    Event::fake([AgentEnrolled::class]);

    ['agent' => $agent, 'response' => $response] = fleet_enroll($this->organization->id, $this->serverId);

    expect(fleet_schema_errors('enroll-response.schema.json', $response->json()))->toBe([])
        ->and($response->json('agent_id'))->toBe($agent->id)
        ->and($response->json('ca_pem'))->toBe(app(CertificateAuthorityService::class)->caPem())
        ->and($response->json('ca_pem'))->toEndWith("-----END CERTIFICATE-----\n")
        ->and($response->json('cert_pem'))->toEndWith("-----END CERTIFICATE-----\n")
        ->and($response->json('endpoints'))->toBe(['api' => 'https://panel.falak.test/agent/v1', 'otlp' => 'https://otlp.falak.test']);

    expect($agent->organization_id)->toBe($this->organization->id)
        ->and($agent->server_id)->toBe($this->serverId)
        ->and($agent->status)->toBe(AgentStatus::Online)
        ->and($agent->hostname)->toBe('web-1')
        ->and($agent->facts['os'])->toBe(['id' => 'ubuntu', 'version' => '24.04']);

    $x509 = new X509;
    $x509->loadX509($response->json('cert_pem'));
    expect($x509->getDNProp('id-at-commonName'))->toBe([$agent->id]);

    Event::assertDispatched(AgentEnrolled::class, fn (AgentEnrolled $e) => $e->agentId === $agent->id
        && $e->serverId === $this->serverId
        && $e->organizationId === $this->organization->id
        && $e->facts['hostname'] === 'web-1');

    expect(AuditEntry::query()->where('action', 'agent.enrolled')->where('organization_id', $this->organization->id)->exists())->toBeTrue();
});

it('rejects a token that was already used', function () {
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, $this->serverId);
    [$csr] = fleet_csr();
    $body = ['token' => $install->token, 'csr_pem' => $csr, 'facts' => fleet_facts()];

    $this->postJson('/agent/v1/enroll', $body)->assertCreated();
    $this->postJson('/agent/v1/enroll', $body)->assertUnauthorized();

    expect(Agent::query()->count())->toBe(1);
});

it('rejects expired and unknown tokens', function () {
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, $this->serverId, ttlMinutes: 5);
    [$csr] = fleet_csr();

    $this->travel(6)->minutes();

    $this->postJson('/agent/v1/enroll', ['token' => $install->token, 'csr_pem' => $csr, 'facts' => fleet_facts()])->assertUnauthorized();
    $this->postJson('/agent/v1/enroll', ['token' => Str::random(48), 'csr_pem' => $csr, 'facts' => fleet_facts()])->assertUnauthorized();
});

it('validates the request against enroll-request.schema.json', function (string $case, string $error) {
    $body = match ($case) {
        'missing csr' => ['token' => 'x', 'facts' => fleet_facts()],
        'extra field' => ['token' => 'x', 'csr_pem' => 'y', 'facts' => fleet_facts(), 'nope' => 1],
        'bad arch' => ['token' => 'x', 'csr_pem' => 'y', 'facts' => fleet_facts(['arch' => 'mips'])],
        'facts not object' => ['token' => 'x', 'csr_pem' => 'y', 'facts' => [1, 2]],
    };

    $this->postJson('/agent/v1/enroll', $body)->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    ['missing csr', 'body'],
    ['extra field', 'body'],
    ['bad arch', 'facts/arch'],
    ['facts not object', 'facts'],
]);

it('rejects non P-256 CSRs without burning the token', function () {
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, $this->serverId);
    [$rsa] = fleet_csr('x', 'rsa');

    $this->postJson('/agent/v1/enroll', ['token' => $install->token, 'csr_pem' => $rsa, 'facts' => fleet_facts()])
        ->assertUnprocessable()->assertJsonValidationErrors('csr_pem');

    [$csr] = fleet_csr();
    $this->postJson('/agent/v1/enroll', ['token' => $install->token, 'csr_pem' => $csr, 'facts' => fleet_facts()])->assertCreated();
});

it('revokes the previous agent of a server on re-enrollment', function () {
    Event::fake([AgentRevoked::class]);

    ['agent' => $first, 'fingerprint' => $oldFingerprint] = fleet_enroll($this->organization->id, $this->serverId);
    ['agent' => $second, 'fingerprint' => $newFingerprint] = fleet_enroll($this->organization->id, $this->serverId);

    expect($first->refresh()->status)->toBe(AgentStatus::Revoked)
        ->and($second->refresh()->status)->toBe(AgentStatus::Online);

    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls($oldFingerprint))->assertUnauthorized();
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls($newFingerprint))->assertNoContent();

    Event::assertDispatched(AgentRevoked::class, fn (AgentRevoked $e) => $e->agentId === $first->id);
});

it('issuing a new install token invalidates the previous unused one for the server', function () {
    $old = app(Enrollment::class)->issueInstallToken($this->organization->id, $this->serverId);
    $new = app(Enrollment::class)->issueInstallToken($this->organization->id, $this->serverId);

    expect(InstallToken::query()->usable()->count())->toBe(1)
        ->and($new->command)->toBe("curl -fsSL https://panel.falak.test/install/{$new->token} | sudo sh")
        ->and($old->token)->not->toBe($new->token);

    [$csr] = fleet_csr();
    $this->postJson('/agent/v1/enroll', ['token' => $old->token, 'csr_pem' => $csr, 'facts' => fleet_facts()])->assertUnauthorized();
});

it('stores only a hash of install tokens', function () {
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, $this->serverId);

    expect(InstallToken::query()->first()->token_hash)->toBe(hash('sha256', $install->token))
        ->and(DB::table('fleet_install_tokens')->where('token_hash', $install->token)->exists())->toBeFalse();
});
