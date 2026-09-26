<?php

use Kiln\Fleet\Infrastructure\Pki\CertificateAuthorityService;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/kiln-ca-cmd-'.bin2hex(random_bytes(4));
    config(['fleet.ca_path' => $this->dir]);
    app()->forgetInstance(CertificateAuthorityService::class);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

/**
 * @return list<string>
 */
function pemBlocks(string $bundle): array
{
    preg_match_all('/-----BEGIN CERTIFICATE-----\n[A-Za-z0-9+\/=\n]+?\n-----END CERTIFICATE-----\n/', $bundle, $m);

    return $m[0];
}

it('initializes the CA and writes a newline-terminated ca.pem', function () {
    $this->artisan('fleet:ca:init')->assertSuccessful();

    $pem = file_get_contents($this->dir.'/ca.pem');
    expect($pem)->toEndWith("-----END CERTIFICATE-----\n")
        ->and(openssl_x509_read($pem))->not->toBeFalse();
});

it('writes a server certificate bundle that parses as leaf + CA', function () {
    $this->artisan('fleet:ca:server-cert', ['hostnames' => ['agents.kiln.test', '10.0.0.5'], '--out' => $this->dir])->assertSuccessful();

    $bundle = file_get_contents($this->dir.'/agent-api.pem');
    $blocks = pemBlocks($bundle);

    expect($bundle)->not->toContain('----------')
        ->and($bundle)->toEndWith("-----END CERTIFICATE-----\n")
        ->and($blocks)->toHaveCount(2)
        ->and(implode('', $blocks))->toBe($bundle);

    $leaf = openssl_x509_parse(openssl_x509_read($blocks[0]));
    $ca = openssl_x509_parse(openssl_x509_read($blocks[1]));

    expect($leaf['subject']['CN'])->toBe('agents.kiln.test')
        ->and($leaf['extensions']['subjectAltName'])->toContain('DNS:agents.kiln.test')->toContain('IP Address:10.0.0.5')
        ->and($ca['subject']['CN'])->toBe('Kiln Agent CA')
        ->and($blocks[1])->toBe(file_get_contents($this->dir.'/ca.pem'));

    // The key matches the leaf and is private to the owner.
    $key = file_get_contents($this->dir.'/agent-api.key');
    expect($key)->toEndWith("-----END PRIVATE KEY-----\n")
        ->and(openssl_x509_check_private_key($blocks[0], $key))->toBeTrue()
        ->and(substr(sprintf('%o', fileperms($this->dir.'/agent-api.key')), -4))->toBe('0600');

    // OpenSSL verifies the chain in the bundle.
    $caFile = $this->dir.'/ca.pem';
    $leafFile = $this->dir.'/leaf.pem';
    file_put_contents($leafFile, $blocks[0]);
    expect(trim((string) shell_exec(sprintf('openssl verify -CAfile %s -purpose sslserver %s 2>&1', escapeshellarg($caFile), escapeshellarg($leafFile)))))->toEndWith('OK');
});

it('normalizes PEM blocks and bundles', function () {
    expect(CertificateAuthorityService::normalizePem("-----BEGIN X-----\r\nAA\r\n-----END X-----"))->toBe("-----BEGIN X-----\nAA\n-----END X-----\n")
        ->and(CertificateAuthorityService::bundle("A\n\n", 'B'))->toBe("A\nB\n");
});
