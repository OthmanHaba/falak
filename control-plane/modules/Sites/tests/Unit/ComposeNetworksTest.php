<?php

use Kiln\Sites\Application\Compose\ComposeNetworks;
use Kiln\Sites\Application\Compose\ServiceReferences;
use Symfony\Component\Yaml\Yaml;

/*
 * A compose service run as its own Kiln site: the stack networks it was on (by their real names) and the stack's
 * services it uses (docs/plans/COMPOSE_APPS.md, follow-up: split-out services keep reaching the stack).
 */

it('names the networks a service is on like Compose does', function () {
    $doc = Yaml::parse(<<<'YAML'
        services:
          api: { image: api }
          web: { image: nginx, networks: [front, back] }
          admin: { image: admin, networks: { back: { aliases: [adm] }, shared: {} } }
          host: { image: x, network_mode: host }
        networks:
          front: {}
          back: { name: shop-backend }
          shared: { external: true }
        YAML);

    expect(ComposeNetworks::of($doc, 'shop', 'api'))->toBe(['shop_default'])
        ->and(ComposeNetworks::of($doc, 'shop', 'web'))->toBe(['shop_front', 'shop-backend'])
        ->and(ComposeNetworks::of($doc, 'shop', 'admin'))->toBe(['shop-backend', 'shared'])
        ->and(ComposeNetworks::of($doc, 'shop', 'host'))->toBe([])
        ->and(ComposeNetworks::of($doc, 'shop', 'missing'))->toBe([]);
});

it('reads the legacy external: {name: x} form', function () {
    $doc = Yaml::parse(<<<'YAML'
        services:
          web: { image: nginx, networks: [proxy, legacy] }
        networks:
          proxy: { external: { name: traefik_proxy } }
          legacy: { external: {} }
        YAML);

    expect(ComposeNetworks::of($doc, 'shop', 'web'))->toBe(['traefik_proxy', 'legacy']);
});

it('sorts out networks the agent can’t join: names Docker refuses, and more than it takes', function () {
    $names = ['shop_default', '-bad', 'has space', ...array_map(fn (int $i) => "net{$i}", range(1, 8))];

    expect(ComposeNetworks::check($names))->toBe([
        'networks' => ['shop_default', 'net1', 'net2', 'net3', 'net4', 'net5', 'net6', 'net7'],
        'skipped' => ['-bad', 'has space', 'net8'],
    ])
        ->and(ComposeNetworks::check(['a', 'b']))->toBe(['networks' => ['a', 'b'], 'skipped' => []])
        ->and(ComposeNetworks::validAlias('api'))->toBeTrue()
        ->and(ComposeNetworks::validAlias(str_repeat('a', 64)))->toBeFalse();
});

it('finds the stack services a service uses: depends_on and hosts in its environment', function () {
    $doc = Yaml::parse(<<<'YAML'
        services:
          api:
            image: api
            depends_on: { postgres: { condition: service_healthy } }
            environment:
              DATABASE_URL: postgres://app:app@postgres:5432/app
              REDIS_URL: redis://redis:6379
              DB_CONNECTION: mysql
              SEARCH: search:7700
          worker: { image: worker, depends_on: [redis, api] }
          postgres: { image: postgres }
          redis: { image: redis }
          search: { image: meilisearch }
          mysql: { image: mysql }
        YAML);

    // DB_CONNECTION=mysql is Laravel's driver, not the mysql service.
    expect(ServiceReferences::uses($doc, 'api'))->toBe(['postgres', 'redis', 'search'])
        ->and(ServiceReferences::uses($doc, 'worker'))->toBe(['api', 'redis'])
        ->and(ServiceReferences::uses($doc, 'redis'))->toBe([]);
});
