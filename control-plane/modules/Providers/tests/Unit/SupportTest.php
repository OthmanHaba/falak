<?php

use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Infrastructure\Adapters\SshKey;
use Kiln\Providers\Infrastructure\Aws\SigV4Signer;

require_once __DIR__.'/../fixtures.php';

it('signs requests exactly like the AWS SigV4 test suite (get-vanilla)', function () {
    $signer = new SigV4Signer('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service');

    $headers = $signer->sign('GET', 'https://example.amazonaws.com/', [], '', new DateTimeImmutable('2015-08-30T12:36:00Z'));

    expect($headers['X-Amz-Date'])->toBe('20150830T123600Z')
        ->and($headers['Authorization'])->toBe(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, '
            .'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31'
        );
});

it('signs the documented IAM ListUsers example with query string and content type', function () {
    $signer = new SigV4Signer('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'iam');

    $headers = $signer->sign(
        'GET',
        'https://iam.amazonaws.com/?Version=2010-05-08&Action=ListUsers',
        ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
        '',
        new DateTimeImmutable('2015-08-30T12:36:00Z'),
    );

    expect($headers['Authorization'])->toEndWith('SignedHeaders=content-type;host;x-amz-date, Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7')
        ->and($headers)->not->toHaveKey('Host');
});

it('normalizes and fingerprints OpenSSH public keys', function () {
    expect(SshKey::normalize(PROVIDERS_TEST_KEY))->toBe('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhfLAQI6GIEE8z6YcLPiiVBw4Tu9QD2miHt6Q8acbUV')
        ->and(SshKey::md5Fingerprint(PROVIDERS_TEST_KEY))->toBe('03:84:3e:7e:c3:64:5d:07:86:ad:8c:f0:43:29:85:7d')
        ->and(SshKey::equals(PROVIDERS_TEST_KEY, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhfLAQI6GIEE8z6YcLPiiVBw4Tu9QD2miHt6Q8acbUV other'))->toBeTrue()
        ->and(SshKey::equals(PROVIDERS_TEST_KEY, 'garbage'))->toBeFalse();
});

it('rejects malformed public keys', function () {
    SshKey::normalize('not-a-key');
})->throws(ProviderException::class, 'Invalid OpenSSH public key.');
