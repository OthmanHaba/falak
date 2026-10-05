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
        // The stack had a REDIS_PASSWORD already: rewritten, not added.
        ComposeRewrites::STACK => ['REDIS_HOST' => '{ref:REDIS_HOST}', 'REDIS_PASSWORD' => '{ref:REDIS_PASSWORD}'],
    ]);
});
