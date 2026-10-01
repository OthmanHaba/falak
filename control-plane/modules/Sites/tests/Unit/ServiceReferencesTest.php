<?php

use Kiln\Sites\Application\Compose\ServiceReferences;

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
        'DATABASE_URL' => '{ref:DATABASE_URL}',
        'DB_HOST' => '{ref:DB_HOST}:{ref:DB_PORT}',
        'DB_USER' => '{ref:DB_USERNAME}',
        'MYSQL_HOST' => '{ref:DB_HOST}',
        'MYSQL_PWD' => '{ref:DB_PASSWORD}',
        'STACK_DB_HOST' => '{ref:DB_HOST}',
    ]);
});

it('finds URLs and hosts pointing at an app service, keeping the path', function () {
    $document = ['services' => [
        'web' => ['environment' => ['API_URL' => 'http://api:8000/v1', 'API_HOST' => 'api', 'APIARY' => 'http://apiary/x']],
        'api' => ['build' => './api'],
    ]];

    expect(ServiceReferences::find($document, 'api', 'site'))->toBe(['API_HOST' => '{host}', 'API_URL' => '{url}/v1']);
});

it('reads environment maps and KEY=value lists', function () {
    expect(ServiceReferences::environment(['A=1', 'B=x=y', 'C', 'D' => true, 'E' => 2, 'F' => null]))
        ->toBe(['A' => '1', 'B' => 'x=y', 'D' => 'true', 'E' => '2']);
});
