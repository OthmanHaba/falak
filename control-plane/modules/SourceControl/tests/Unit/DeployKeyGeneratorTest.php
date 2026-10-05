<?php

use Falak\SourceControl\Infrastructure\DeployKeyGenerator;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;

it('generates OpenSSH ed25519 key pairs with a SHA256 fingerprint', function () {
    $key = (new DeployKeyGenerator)->generate('falak shop@acme');

    expect($key['public_key'])->toStartWith('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5')
        ->and($key['public_key'])->toEndWith(' falak-shop@acme')
        ->and($key['private_key'])->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and($key['fingerprint'])->toMatch('/^SHA256:[A-Za-z0-9+\/]{43}$/');

    $private = PublicKeyLoader::loadPrivateKey($key['private_key']);
    expect($private)->toBeInstanceOf(EC\PrivateKey::class)
        ->and(trim($private->getPublicKey()->toString('OpenSSH', ['comment' => 'falak-shop@acme'])))->toBe($key['public_key']);

    $blob = base64_decode(explode(' ', $key['public_key'])[1]);
    expect($key['fingerprint'])->toBe('SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='));
});

it('generates a different key every time', function () {
    $generator = new DeployKeyGenerator;

    expect($generator->generate('a')['public_key'])->not->toBe($generator->generate('a')['public_key']);
});
