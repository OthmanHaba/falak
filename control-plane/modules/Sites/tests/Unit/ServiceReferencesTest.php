<?php

use Kiln\Sites\Application\Compose\ServiceReferences;
use Kiln\Sites\Contracts\Data\ComposeRewrites;

it('finds the variables of a stack that point at a database service, with their companions', function () {
    $document = ['services' => [
        'web' => ['environment' => [
            'DATABASE_URL' => 'mysql://app:pw@mysql:3306/app?charset=utf8mb4',
            'DB_HOST' => 'mysql:3306',
            'DB_USER' => 'app',
            'DB_CONNECTION' => 'mysql',
            'CACHE_HOST' => 'redis',
            'MYSQL_HOME' => '/etc',
        ]],
        'cron' => ['environment' => ['MYSQL_PWD=pw', 'MYSQL_HOST=mysql', 'UNRELATED']],
        'admin' => ['environment' => ['DB_PASSWORD' => 'pw']], // no pointer at mysql in this service: left alone
        'mysql' => ['image' => 'mysql:8', 'environment' => ['MYSQL_DATABASE' => 'app']],
    ]];

    // DB_CONNECTION=mysql is a driver name, not the service; MYSQL_HOME is no companion key.
    expect(ServiceReferences::find($document, 'mysql', 'database', ['STACK_DB_HOST' => 'mysql', 'DRIVER' => 'mysql']))->toBe([
        'web' => [
            'DATABASE_URL' => '{ref:DATABASE_URL}',
            'DB_HOST' => '{ref:DB_HOST}:{ref:DB_PORT}',
            'DB_USER' => '{ref:DB_USERNAME}',
        ],
        'cron' => ['MYSQL_HOST' => '{ref:DB_HOST}', 'MYSQL_PWD' => '{ref:DB_PASSWORD}'],
        ComposeRewrites::STACK => ['STACK_DB_HOST' => '{ref:DB_HOST}'],
    ]);
});

it('finds URLs and hosts pointing at an app service, keeping the path', function () {
    $document = ['services' => [
        'web' => ['environment' => ['API_URL' => 'http://api:8000/v1', 'API_HOST' => 'api', 'APIARY' => 'http://apiary/x']],
        'api' => ['build' => './api'],
    ]];

    expect(ServiceReferences::find($document, 'api', 'site'))->toBe(['web' => ['API_HOST' => '{host}', 'API_URL' => '{url}/v1']]);
});

it('reads environment maps and KEY=value lists', function () {
    expect(ServiceReferences::environment(['A=1', 'B=x=y', 'C', 'D' => true, 'E' => 2, 'F' => null]))
        ->toBe(['A' => '1', 'B' => 'x=y', 'D' => 'true', 'E' => '2']);
});

it('finds a Redis service inside values: URLs (path kept), host:port pairs, host keys, its companions, and adds REDIS_PASSWORD', function () {
    $document = ['services' => [
        'app' => ['environment' => [
            'REDIS_HOST' => 'redis',
            'REDIS_PORT' => '6379',
            'CACHE_URL' => 'redis://redis:6379/2',
            'CELERY' => 'redis://user:pw@redis:6379/0?timeout=5, valkey://redis',
            'CACHE_DRIVER' => 'redis',
            'NOT_IT' => 'myredis:6379 redis.example.com:6379 redis_2:6379',
        ]],
        'sidekiq' => ['environment' => ['REDIS_URL=redis://redis', 'VALKEY_PASSWORD=x', 'ADDR=tcp://redis:6379']],
        'other' => ['environment' => ['REDIS_HOST' => 'elsewhere', 'REDIS_PASSWORD' => 'x']],
        'redis' => ['image' => 'redis:7'],
    ]];

    expect(ServiceReferences::find($document, 'redis', 'cache', ['REDIS_HOST' => 'redis', 'REDIS_PASSWORD' => '']))->toBe([
        'app' => [
            'CACHE_URL' => '{ref:REDIS_URL}/2',
            'CELERY' => '{ref:REDIS_URL}/0?timeout=5, {ref:REDIS_URL}',
            'REDIS_HOST' => '{ref:REDIS_HOST}',
            'REDIS_PASSWORD' => '{ref:REDIS_PASSWORD}',
            'REDIS_PORT' => '{ref:REDIS_PORT}',
        ],
        'sidekiq' => [
            'ADDR' => 'tcp://{ref:REDIS_HOST}:{ref:REDIS_PORT}',
            'REDIS_URL' => '{ref:REDIS_URL}',
            'VALKEY_PASSWORD' => '{ref:REDIS_PASSWORD}',
        ],
        // The stack had a REDIS_PASSWORD already: rewritten; REDIS_PORT added.
        ComposeRewrites::STACK => ['REDIS_HOST' => '{ref:REDIS_HOST}', 'REDIS_PASSWORD' => '{ref:REDIS_PASSWORD}', 'REDIS_PORT' => '{ref:REDIS_PORT}'],
    ]);
});

it('rewrites only the companions of a group whose host is the extracted Redis service', function () {
    $document = ['services' => [
        'app' => ['environment' => [
            'REDIS_HOST' => 'cache',
            'REDIS_PORT' => '6379',
            'REDIS_PASSWORD' => 'a',
            'REDIS_QUEUE_HOST' => 'queue',
            'REDIS_QUEUE_PORT' => '6380',
            'REDIS_QUEUE_PASSWORD' => 'b',
        ]],
        'worker' => ['environment' => [
            'REDIS_QUEUE_HOST' => 'cache',
            'REDIS_QUEUE_PORT' => '6379',
            'REDIS_HOST' => 'queue',
            'REDIS_PORT' => '6380',
        ]],
        'cache' => ['image' => 'redis:7'],
        'queue' => ['image' => 'redis:7'],
    ]];

    expect(ServiceReferences::find($document, 'cache', 'cache'))->toBe([
        'app' => ['REDIS_HOST' => '{ref:REDIS_HOST}', 'REDIS_PASSWORD' => '{ref:REDIS_PASSWORD}', 'REDIS_PORT' => '{ref:REDIS_PORT}'],
        // REDIS_HOST / REDIS_PORT point at the kept `queue`: untouched, and REDIS_HOST gains nothing.
        'worker' => ['REDIS_QUEUE_HOST' => '{ref:REDIS_HOST}', 'REDIS_QUEUE_PORT' => '{ref:REDIS_PORT}'],
    ])->and(ServiceReferences::find($document, 'queue', 'cache'))->toBe([
        'app' => ['REDIS_QUEUE_HOST' => '{ref:REDIS_HOST}', 'REDIS_QUEUE_PASSWORD' => '{ref:REDIS_PASSWORD}', 'REDIS_QUEUE_PORT' => '{ref:REDIS_PORT}'],
        'worker' => ['REDIS_HOST' => '{ref:REDIS_HOST}', 'REDIS_PASSWORD' => '{ref:REDIS_PASSWORD}', 'REDIS_PORT' => '{ref:REDIS_PORT}'],
    ]);
});

it('leaves TLS URLs of a Redis service alone and lists them, and takes <service>:<port> only where it is an address', function () {
    $document = ['services' => [
        'app' => ['environment' => [
            'REDIS_URL' => 'rediss://:pw@redis:6380/0',
            'REDIS_PASSWORD' => 'pw',
            'IMG' => 'redis:7',
            'IMAGE' => 'redis:7-alpine',
            'BASE' => 'ghcr.io/acme/redis:6379',
            'CACHE' => 'redis:6379',
            'REDIS_NODES' => 'redis:7000,other:7000',
            'BROKER' => 'tcp://redis:6379',
            'SHORT_HOST' => 'redis:6',
        ]],
        'redis' => ['image' => 'redis:7'],
    ]];

    expect(ServiceReferences::find($document, 'redis', 'cache', ['TLS_CACHE' => 'valkeys://redis']))->toBe([
        'app' => [
            'BROKER' => 'tcp://{ref:REDIS_HOST}:{ref:REDIS_PORT}',
            'CACHE' => '{ref:REDIS_HOST}:{ref:REDIS_PORT}',
            'REDIS_NODES' => '{ref:REDIS_HOST}:{ref:REDIS_PORT},other:7000',
            'SHORT_HOST' => '{ref:REDIS_HOST}:{ref:REDIS_PORT}',
        ],
    ])->and(ServiceReferences::tlsReferences($document, 'redis', ['TLS_CACHE' => 'valkeys://redis']))
        ->toBe(['REDIS_URL (app)', "TLS_CACHE (the stack's variables)"]);
});

it('takes REDIS_ companions next to a host key of another prefix when the group points at no other service, and reports them otherwise', function () {
    $document = ['services' => [
        'app' => ['environment' => ['QUEUE_HOST' => 'cache', 'REDIS_PORT' => '6379', 'REDIS_PASSWORD' => 'x', 'DB_HOST' => 'db']],
        'worker' => ['environment' => ['QUEUE_HOST' => 'cache', 'SESSION_HOST' => 'sessions', 'REDIS_PORT' => '6379']],
        'cache' => ['image' => 'redis:7'],
        'sessions' => ['image' => 'redis:7'],
    ]];

    // app: only cache is a service its values point at (db isn't one of the stack's) → REDIS_PORT / REDIS_PASSWORD are cache's.
    expect(ServiceReferences::find($document, 'cache', 'cache'))->toBe([
        'app' => ['QUEUE_HOST' => '{ref:REDIS_HOST}', 'REDIS_PASSWORD' => '{ref:REDIS_PASSWORD}', 'REDIS_PORT' => '{ref:REDIS_PORT}'],
        // worker also points at sessions: whose REDIS_PORT is it? Left, and reported.
        'worker' => ['QUEUE_HOST' => '{ref:REDIS_HOST}'],
    ])->and(ServiceReferences::unclearCompanions($document, 'cache'))->toBe(['REDIS_PORT (worker)']);
});

it('finds the healthchecks of other services that name a service as a host', function () {
    $document = ['services' => [
        'app' => ['healthcheck' => ['test' => ['CMD', 'redis-cli', '-h', 'redis', 'ping']]],
        'probe' => ['healthcheck' => ['test' => 'redis-cli -h redis:6379 ping || exit 1']],
        'self' => ['healthcheck' => ['test' => ['CMD-SHELL', 'redis-cli -h $$REDIS_HOST ping']]],
        'other' => ['healthcheck' => ['test' => ['CMD', 'redis-server', '--version', 'myredis', 'redis.example.com']]],
        'redis' => ['image' => 'redis:7', 'healthcheck' => ['test' => ['CMD', 'redis-cli', 'ping']]],
    ]];

    expect(ServiceReferences::healthchecksNaming($document, 'redis'))->toBe(['app', 'probe']);
});
