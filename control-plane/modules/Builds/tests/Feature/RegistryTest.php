<?php

use Falak\Builds\Application\Registry;

/*
 * The built-in registry address as falak-ctl / install.sh write it: registry.<domain>, with the HTTPS port when the
 * install does not use 443 (deploy/compose.yml passes FALAK_REGISTRY_URL).
 */

it('names images and credentials after the registry host, port included', function () {
    config([
        'builds.registry.url' => 'https://registry.falak.example.com:8443/',
        'builds.registry.namespace' => 'falak',
        'builds.registry.username' => 'falak',
        'builds.registry.password' => 'secret',
    ]);
    $registry = app(Registry::class);

    expect($registry->url())->toBe('registry.falak.example.com:8443')
        ->and($registry->image('shop', '01J9ZQ4N8V2M6R0T3W5Y7B9D1F'))->toBe('registry.falak.example.com:8443/falak/shop:01j9zq4n8v2m6r0t3w5y7b9d1f')
        ->and($registry->auth())->toBe(['server' => 'registry.falak.example.com:8443', 'username' => 'falak', 'password' => 'secret']);
});

it('sends no credentials until the install has them', function () {
    config(['builds.registry.url' => 'registry.falak.local', 'builds.registry.username' => '', 'builds.registry.password' => '']);

    expect(app(Registry::class)->auth())->toBeNull();
});
