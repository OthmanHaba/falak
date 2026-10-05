<?php

use Falak\Fleet\Domain\Models\CertificateAuthority;
use Falak\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Falak\Fleet\Infrastructure\Pki\InvalidCsr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\File\X509;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->caDir = sys_get_temp_dir().'/falak-ca-'.bin2hex(random_bytes(4));
    config(['fleet.ca_path' => $this->caDir]);
    app()->forgetInstance(CertificateAuthorityService::class);
    $this->ca = app(CertificateAuthorityService::class);
});

afterEach(function () {
    @unlink($this->caDir.'/ca.pem');
    @rmdir($this->caDir);
});

function opensslVerify(string $caPem, string $certPem, string $purpose): string
{
    $dir = sys_get_temp_dir().'/falak-verify-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/ca.pem", $caPem);
    file_put_contents("{$dir}/cert.pem", $certPem);
    $output = (string) shell_exec(sprintf('openssl verify -CAfile %s -purpose %s %s 2>&1', escapeshellarg("{$dir}/ca.pem"), $purpose, escapeshellarg("{$dir}/cert.pem")));
    array_map('unlink', glob("{$dir}/*") ?: []);
    rmdir($dir);

    return trim($output);
}

it('creates a self-signed ECDSA P-256 CA once, encrypted at rest, and writes ca.pem', function () {
    $authority = $this->ca->current();

    expect(CertificateAuthority::query()->count())->toBe(1)
        ->and($this->ca->current()->id)->toBe($authority->id)
        ->and(file_get_contents($this->caDir.'/ca.pem'))->toBe($authority->certificate_pem);

    $raw = DB::table('fleet_certificate_authorities')->value('private_key');
    expect($raw)->not->toContain('PRIVATE KEY')
        ->and($authority->private_key)->toContain('PRIVATE KEY');

    $x509 = new X509;
    $cert = $x509->loadX509($authority->certificate_pem);
    expect($x509->getPublicKey()->getCurve())->toBe('secp256r1')
        ->and($x509->getExtension('id-ce-basicConstraints'))->toMatchArray(['cA' => true])
        ->and($x509->getDNProp('id-at-commonName'))->toBe(['Falak Agent CA'])
        ->and($cert)->not->toBeFalse();

    expect(opensslVerify($authority->certificate_pem, $authority->certificate_pem, 'any'))->toEndWith('OK');
});

it('rewrites ca.pem when it is missing', function () {
    $pem = $this->ca->caPem();
    unlink($this->caDir.'/ca.pem');

    app()->forgetInstance(CertificateAuthorityService::class);
    app(CertificateAuthorityService::class)->current();

    expect(file_get_contents($this->caDir.'/ca.pem'))->toBe($pem);
});

it('signs an agent CSR as a 90-day clientAuth certificate with CN = agent id', function () {
    [$csr] = fleet_csr('attacker-chosen-cn');

    $issued = $this->ca->signAgentCsr($csr, '01JAGENT0000000000000000AA', '01JORG00000000000000000000');

    $x509 = new X509;
    $x509->loadX509($issued->pem);

    expect($x509->getDNProp('id-at-commonName'))->toBe(['01JAGENT0000000000000000AA'])
        ->and($x509->getExtension('id-ce-extKeyUsage'))->toBe(['id-kp-clientAuth'])
        ->and($x509->getExtension('id-ce-basicConstraints'))->toMatchArray(['cA' => false])
        ->and($x509->validateDate(now()->addDays(89)->toDateTime()))->toBeTrue()
        ->and($x509->validateDate(now()->addDays(91)->toDateTime()))->toBeFalse()
        ->and($issued->fingerprint)->toBe(hash('sha256', CertificateAuthorityService::der($issued->pem)))
        ->and($issued->fingerprint)->toMatch('/^[a-f0-9]{64}$/');

    expect(opensslVerify($this->ca->caPem(), $issued->pem, 'sslclient'))->toEndWith('OK');
});

it('keeps the CSR public key (the agent private key never leaves the host)', function () {
    [$csr, $key] = fleet_csr();
    $issued = $this->ca->signAgentCsr($csr, '01JAGENT0000000000000000AA', '01JORG00000000000000000000');

    $x509 = new X509;
    $x509->loadX509($issued->pem);

    expect($x509->getPublicKey()->toString('PKCS8'))->toBe($key->getPublicKey()->toString('PKCS8'));
});

it('rejects CSRs that are not ECDSA P-256', function (string $algorithm) {
    [$csr] = fleet_csr('x', $algorithm);

    $this->ca->signAgentCsr($csr, '01JAGENT0000000000000000AA', '01JORG00000000000000000000');
})->with(['rsa', 'nistp384'])->throws(InvalidCsr::class, 'ECDSA P-256');

it('rejects garbage and tampered CSRs', function () {
    expect(fn () => $this->ca->signAgentCsr('not a csr', 'a', 'o'))->toThrow(InvalidCsr::class);

    [$csr] = fleet_csr();
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $csr));
    $der[strlen($der) - 5] = chr(ord($der[strlen($der) - 5]) ^ 0xFF);
    $tampered = "-----BEGIN CERTIFICATE REQUEST-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END CERTIFICATE REQUEST-----';

    expect(fn () => $this->ca->signAgentCsr($tampered, 'a', 'o'))->toThrow(InvalidCsr::class);
});

it('accepts CSRs produced by OpenSSL (same format as Go crypto/x509)', function () {
    $dir = sys_get_temp_dir().'/falak-openssl-'.bin2hex(random_bytes(4));
    mkdir($dir);
    shell_exec(sprintf('openssl ecparam -name prime256v1 -genkey -noout -out %1$s/k.pem 2>/dev/null && openssl req -new -key %1$s/k.pem -subj "/CN=host/O=falak-agent" -out %1$s/r.csr 2>/dev/null', escapeshellarg($dir)));
    $csr = (string) file_get_contents("{$dir}/r.csr");
    array_map('unlink', glob("{$dir}/*") ?: []);
    rmdir($dir);

    $issued = $this->ca->signAgentCsr($csr, '01JAGENT0000000000000000AA', '01JORG00000000000000000000');

    expect(opensslVerify($this->ca->caPem(), $issued->pem, 'sslclient'))->toEndWith('OK');
});

it('issues serverAuth certificates with SANs for the agent-facing edge', function () {
    ['certificate' => $issued, 'private_key_pem' => $key] = $this->ca->issueServerCertificate(['agents.falak.test', '203.0.113.5']);

    $x509 = new X509;
    $x509->loadX509($issued->pem);

    expect($x509->getExtension('id-ce-extKeyUsage'))->toBe(['id-kp-serverAuth'])
        ->and($x509->getExtension('id-ce-subjectAltName'))->toBe([['dNSName' => 'agents.falak.test'], ['iPAddress' => '203.0.113.5']])
        ->and($key)->toContain('PRIVATE KEY');

    expect(opensslVerify($this->ca->caPem(), $issued->pem, 'sslserver'))->toEndWith('OK');
});

it('uses unique random serials', function () {
    [$csr] = fleet_csr();
    $serials = collect(range(1, 5))->map(fn () => $this->ca->signAgentCsr($csr, 'a', 'o')->serial);

    expect($serials->unique())->toHaveCount(5);
});
