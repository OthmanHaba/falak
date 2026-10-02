<?php

use Kiln\Builds\Application\Registry;

/*
 * The built-in registry address as kiln-ctl / install.sh write it: registry.<domain>, with the HTTPS port when the
 * install does not use 443 (deploy/compose.yml passes KILN_REGISTRY_URL).
 */

it('names images and credentials after the registry host, port included', function () {
    config([
        'builds.registry.url' => 'https://registry.kiln.example.com:8443/',
        'builds.registry.namespace' => 'kiln',
        'builds.registry.username' => 'kiln',
        'builds.registry.password' => 'secret',
    ]);
    $registry = app(Registry::class);

    expect($registry->url())->toBe('registry.kiln.example.com:8443')
        ->and($registry->image('shop', '01J9ZQ4N8V2M6R0T3W5Y7B9D1F'))->toBe('registry.kiln.example.com:8443/kiln/shop:01j9zq4n8v2m6r0t3w5y7b9d1f')
        ->and($registry->auth())->toBe(['server' => 'registry.kiln.example.com:8443', 'username' => 'kiln', 'password' => 'secret']);
});

it('sends no credentials until the install has them', function () {
    config(['builds.registry.url' => 'registry.kiln.local', 'builds.registry.username' => '', 'builds.registry.password' => '']);

    expect(app(Registry::class)->auth())->toBeNull();
});
