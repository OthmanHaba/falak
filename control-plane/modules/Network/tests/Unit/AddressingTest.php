<?php

use Kiln\Network\Domain\Support\AddressRules;
use Kiln\Network\Domain\Support\Ipv4Cidr;
use Kiln\Network\Infrastructure\CanonicalJson;
use Kiln\Network\Infrastructure\WireGuardKeys;

it('normalizes and inspects IPv4 CIDRs', function () {
    $range = Ipv4Cidr::parse('10.90.0.7/24');

    expect((string) $range)->toBe('10.90.0.0/24')
        ->and($range->size())->toBe(256)
        ->and($range->contains('10.90.0.255'))->toBeTrue()
        ->and($range->contains('10.90.1.1'))->toBeFalse()
        ->and($range->overlaps(Ipv4Cidr::parse('10.90.0.128/25')))->toBeTrue()
        ->and($range->overlaps(Ipv4Cidr::parse('10.91.0.0/24')))->toBeFalse()
        ->and(Ipv4Cidr::isValid('10.0.0.0/33'))->toBeFalse()
        ->and(Ipv4Cidr::isValid('300.0.0.0/8'))->toBeFalse()
        ->and(Ipv4Cidr::isValid('fd00::/64'))->toBeFalse();
});

it('allocates the lowest free host address, never network or broadcast, until exhausted', function () {
    $range = Ipv4Cidr::parse('10.90.0.0/30'); // hosts .1 and .2

    expect($range->firstFree([]))->toBe('10.90.0.1')
        ->and($range->firstFree(['10.90.0.1']))->toBe('10.90.0.2')
        ->and($range->firstFree(['10.90.0.2']))->toBe('10.90.0.1')
        ->and($range->firstFree(['10.90.0.1', '10.90.0.2']))->toBeNull();
});

it('validates ports and sources strictly', function (string $value, bool $port, bool $source) {
    expect(AddressRules::isPort($value))->toBe($port)
        ->and(AddressRules::isSource($value))->toBe($source);
})->with([
    ['22', true, false],
    ['8000-8100', true, false],
    ['0', false, false],
    ['65536', false, false],
    ['9000-8000', false, false],
    ['022', false, false],
    ['203.0.113.10', false, true],
    ['203.0.113.0/24', false, true],
    ['203.0.113.0/33', false, false],
    ['2001:db8::/32', false, true],
    ['2001:db8::1', false, true],
    ['2001:db8::/129', false, false],
    ['example.com', false, false],
    ['10.0.0.0/08', false, false],
]);

it('generates WireGuard key pairs whose public key is derivable from the private key', function () {
    $keys = new WireGuardKeys;
    $pair = $keys->generate();
    $raw = base64_decode($pair['private'], true);

    expect($pair['public'])->toMatch('#^[A-Za-z0-9+/]{42,43}=$#')
        ->and(strlen($pair['public']))->toBe(44)
        ->and(strlen($raw))->toBe(32)
        ->and(ord($raw[0]) & 7)->toBe(0)
        ->and(ord($raw[31]) & 128)->toBe(0)
        ->and(ord($raw[31]) & 64)->toBe(64)
        ->and($keys->publicKeyFor($pair['private']))->toBe($pair['public'])
        ->and(WireGuardKeys::isPublicKey($pair['public']))->toBeTrue()
        ->and($keys->generate()['public'])->not->toBe($pair['public']);
});

it('derives the RFC 7748 X25519 public key', function () {
    // RFC 7748 §6.1 (Alice).
    $private = base64_encode(hex2bin('77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a'));

    expect(bin2hex(base64_decode((new WireGuardKeys)->publicKeyFor($private))))
        ->toBe('8520f0098930a754748b7ddcb43ef75a0dbf3a0d26381af4eba4a98eaa9b4e6a');
});

it('hashes documents independent of key order', function () {
    expect(CanonicalJson::hash(['b' => 1, 'a' => ['y' => 2, 'x' => [3, 1]]]))
        ->toBe(CanonicalJson::hash(['a' => ['x' => [3, 1], 'y' => 2], 'b' => 1]))
        ->not->toBe(CanonicalJson::hash(['a' => ['x' => [1, 3], 'y' => 2], 'b' => 1]));
});
