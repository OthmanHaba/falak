<?php

use Kiln\Servers\Domain\Models\SshKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;

it('parses OpenSSH keys and computes the SHA256 fingerprint like ssh-keygen', function () {
    $key = EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH', ['comment' => 'dev@laptop']);

    $parsed = SshKey::parse($key);
    [$type, $blob] = explode(' ', $key);

    expect($parsed['public_key'])->toBe("{$type} {$blob}")
        ->and($parsed['comment'])->toBe('dev@laptop')
        ->and($parsed['fingerprint'])->toBe('SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($blob), true)), '='));

    $file = tempnam(sys_get_temp_dir(), 'key');
    file_put_contents($file, $key);
    $keygen = trim((string) shell_exec('ssh-keygen -lf '.escapeshellarg($file).' 2>/dev/null'));
    unlink($file);

    if ($keygen !== '') {
        expect($keygen)->toContain($parsed['fingerprint']);
    }
});

it('accepts RSA ≥ 2048 and ECDSA keys', function () {
    expect(SshKey::parse(RSA::createKey(2048)->getPublicKey()->toString('OpenSSH'))['public_key'])->toStartWith('ssh-rsa ')
        ->and(SshKey::parse(EC::createKey('nistp256')->getPublicKey()->toString('OpenSSH'))['public_key'])->toStartWith('ecdsa-sha2-nistp256 ');
});

it('rejects invalid keys', function (string $key) {
    SshKey::parse($key);
})->with([
    'garbage' => 'hello world',
    'private key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
    'bad base64' => 'ssh-ed25519 !!!!',
    'type mismatch' => 'ssh-rsa '.base64_encode(pack('N', 11).'ssh-ed25519'.str_repeat('a', 32)),
    'short rsa' => fn () => RSA::createKey(1024)->getPublicKey()->toString('OpenSSH'),
])->throws(InvalidArgumentException::class);
