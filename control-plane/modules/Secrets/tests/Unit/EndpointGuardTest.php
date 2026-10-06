<?php

use Falak\Secrets\Infrastructure\Providers\EndpointGuard;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;

it('checks IPv4 addresses embedded in IPv6 ones, and refuses site-local IPv6', function () {
    $guard = new EndpointGuard(fn (string $host) => match ($host) {
        'mapped-hex.example.com' => ['::ffff:a9fe:a9fe'],
        'compat.example.com' => ['::169.254.169.254'],
        'nat64.example.com' => ['64:ff9b::a9fe:a9fe'],
        '6to4.example.com' => ['2002:a9fe:a9fe::1'],
        '6to4-private.example.com' => ['2002:c0a8:0101::1'],
        'site-local.example.com' => ['fec0::1'],
        default => [],
    });

    foreach (['mapped-hex', 'compat', 'nat64', '6to4', 'site-local'] as $host) {
        expect(fn () => $guard->check("https://{$host}.example.com", true))->toThrow(ProviderFailure::class, 'never allowed');
    }

    expect(fn () => $guard->check('https://6to4-private.example.com', false))->toThrow(ProviderFailure::class, 'private or reserved')
        ->and($guard->check('https://6to4-private.example.com', true))->toBe(['2002:c0a8:0101::1'])
        ->and(fn () => $guard->check('https://[::ffff:127.0.0.1]', false))->toThrow(ProviderFailure::class, 'private or reserved')
        ->and(fn () => $guard->check('https://[::ffff:7f00:1]', false))->toThrow(ProviderFailure::class, 'private or reserved')
        ->and(fn () => $guard->check('https://[::1]', false))->toThrow(ProviderFailure::class, 'private or reserved')
        ->and(fn () => $guard->check('https://10.1.2.3', false))->toThrow(ProviderFailure::class, 'private or reserved')
        ->and($guard->check('https://[::1]', true))->toBe(['::1']);
});

it('refuses numeric hosts that HTTP clients read as addresses', function (string $host) {
    $guard = new EndpointGuard(fn () => ['93.184.216.34']);

    expect(fn () => $guard->check("https://{$host}", true))->toThrow(ProviderFailure::class, 'not a canonical address');
})->with(['2130706433', '0x7f.1', '0177.0.0.1', '127.1']);
