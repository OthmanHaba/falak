<?php

use Falak\Edge\Domain\Certificates\PemCertificate;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Http\Rules\Cidr;
use Falak\Edge\Infrastructure\PayloadHash;

require_once __DIR__.'/../Support/helpers.php';

it('parses a certificate with SANs', function () {
    $pem = edge_self_signed(['Example.com', '*.example.com']);
    $parsed = PemCertificate::parse($pem['cert'], $pem['key']);

    expect($parsed->domains)->toBe(['example.com', '*.example.com'])
        ->and($parsed->fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and($parsed->notAfter > new DateTimeImmutable('+80 days'))->toBeTrue();
});

it('rejects mismatched keys and bad chains', function () {
    $a = edge_self_signed(['a.com']);
    $b = edge_self_signed(['b.com']);

    expect(fn () => PemCertificate::parse($a['cert'], $b['key']))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => PemCertificate::parse($a['cert'], $a['key'], 'junk'))->toThrow(InvalidArgumentException::class, 'chain')
        ->and(PemCertificate::parse($a['cert'], $a['key'], $b['cert'])->chainPem)->toContain('BEGIN CERTIFICATE');
});

it('matches hosts against certificate names', function () {
    $certificate = new Certificate(['domains' => ['example.com', '*.example.com']]);

    expect($certificate->covers('example.com'))->toBeTrue()
        ->and($certificate->covers('www.example.com'))->toBeTrue()
        ->and($certificate->covers('a.b.example.com'))->toBeFalse()
        ->and($certificate->covers('example.org'))->toBeFalse();
});

it('validates CIDRs', function (string $value, bool $valid) {
    expect(Cidr::valid($value))->toBe($valid);
})->with([
    ['10.0.0.0/8', true], ['203.0.113.7', true], ['2001:db8::/32', true], ['10.0.0.0/33', false], ['nope', false], ['10.0.0.0/x', false],
]);

it('hashes payloads canonically', function () {
    expect(PayloadHash::of(['b' => 1, 'a' => ['y' => 1, 'x' => 2]]))->toBe(PayloadHash::of(['a' => ['x' => 2, 'y' => 1], 'b' => 1]))
        ->and(PayloadHash::of(['l' => [1, 2]]))->not->toBe(PayloadHash::of(['l' => [2, 1]]));
});
