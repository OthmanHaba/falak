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
