<?php

use Falak\Fleet\Contracts\ServerCertificates;
use Falak\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\File\X509;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->caDir = sys_get_temp_dir().'/falak-ca-'.bin2hex(random_bytes(4));
    config(['fleet.ca_path' => $this->caDir]);
    app()->forgetInstance(CertificateAuthorityService::class);
});

afterEach(function () {
    @unlink($this->caDir.'/ca.pem');
    @rmdir($this->caDir);
});

it('issues server certificates from the Falak CA for names and addresses', function () {
    $issued = app(ServerCertificates::class)->issue(['falak-db-01abc', '127.0.0.1', '10.0.0.5'], 30);

    $x509 = new X509;
    $cert = $x509->loadX509($issued->certificatePem);
    $names = collect($x509->getExtension('id-ce-subjectAltName'))->map(fn (array $name) => $name['dNSName'] ?? $name['iPAddress'] ?? null)->all();

    $ca = new X509;
    $ca->loadX509($issued->caPem);
    $x509->loadCA($issued->caPem);

    expect($cert)->not->toBeFalse()
        ->and($names)->toBe(['falak-db-01abc', '127.0.0.1', '10.0.0.5'])
        ->and($x509->validateSignature())->toBeTrue()
        ->and($issued->caPem)->toBe(app(CertificateAuthorityService::class)->caPem())
        ->and($issued->privateKeyPem)->toStartWith('-----BEGIN PRIVATE KEY-----')
        ->and($issued->certificatePem)->toEndWith("-----END CERTIFICATE-----\n")
        ->and($issued->notAfter->getTimestamp())->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->and($issued->notAfter->getTimestamp())->toBeLessThan(now()->addDays(31)->getTimestamp());
});

it('refuses to issue a certificate without a hostname', function () {
    app(ServerCertificates::class)->issue(['']);
})->throws(InvalidArgumentException::class);
