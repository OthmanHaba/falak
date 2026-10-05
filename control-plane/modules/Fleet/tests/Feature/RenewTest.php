<?php

use Falak\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Illuminate\Support\Str;
use phpseclib3\File\X509;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/falak-ca-test']);
    [, $organization] = memberOf();
    $this->enrolled = fleet_enroll($organization->id, (string) Str::ulid());
});

it('renews the certificate and retires the old one once the new one is used', function () {
    [$csr] = fleet_csr();
    $old = $this->enrolled['fingerprint'];

    $response = $this->postJson('/agent/v1/renew', ['csr_pem' => $csr], fleet_mtls($old))->assertOk();
    $new = CertificateAuthorityService::fingerprint($response->json('cert_pem'));

    expect(array_keys($response->json()))->toBe(['cert_pem'])
        ->and($new)->not->toBe($old);

    // Old certificate keeps working until the agent switches.
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls($old))->assertNoContent();
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls($new))->assertNoContent();
    $this->postJson('/agent/v1/heartbeat', fleet_heartbeat(), fleet_mtls($old))->assertUnauthorized();

    $x509 = new X509;
    $x509->loadX509($response->json('cert_pem'));
    expect($x509->getDNProp('id-at-commonName'))->toBe([$this->enrolled['agent']->id]);
});

it('validates the renew body', function (string $case) {
    $body = match ($case) {
        'empty' => (object) [],
        'garbage' => ['csr_pem' => 'nope'],
        'rsa' => ['csr_pem' => fleet_csr('x', 'rsa')[0]],
        'extra' => ['csr_pem' => fleet_csr()[0], 'x' => 1],
    };

    $this->postJson('/agent/v1/renew', (array) $body === [] ? [] : $body, fleet_mtls($this->enrolled['fingerprint']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($case === 'empty' ? 'body' : 'csr_pem');
})->with(['empty', 'garbage', 'rsa', 'extra']);
