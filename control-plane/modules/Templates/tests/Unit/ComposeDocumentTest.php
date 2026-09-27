<?php

use Kiln\Templates\Application\Compose\ComposeDocument;
use Kiln\Templates\Application\Compose\KilnPlaceholders;
use Kiln\Templates\Domain\InvalidTemplate;

require_once __DIR__.'/../Support/helpers.php';

it('finds Compose interpolation but not escapes, Kiln placeholders or comments', function () {
    $compose = ComposeDocument::parse(TEMPLATES_FIXTURE_COMPOSE."\n".'# ${IN_A_COMMENT}');
    $names = array_column($compose->variables(), 'optional', 'name');

    expect($names)->toBe([
        'APP_SECRET' => false, 'TIMEZONE' => true, 'KILN_DEPLOYMENT_ID' => false, 'ADMIN_EMAIL' => false, 'DB_PASSWORD' => false,
        'SIGNUPS' => false, 'WORKERS' => false, 'PUBLIC_URL' => false, 'DATABASE_URL' => false,
    ]);
});

it('scans interpolation forms', function (string $value, array $expected) {
    expect(ComposeDocument::scan($value))->toBe($expected);
})->with([
    'braced' => ['${A}', [['name' => 'A', 'optional' => false]]],
    'unbraced' => ['x $B y', [['name' => 'B', 'optional' => false]]],
    'default' => ['${C:-x}', [['name' => 'C', 'optional' => true]]],
    'unset default' => ['${C-x}', [['name' => 'C', 'optional' => true]]],
    'required' => ['${D:?missing}', [['name' => 'D', 'optional' => false]]],
    'nested' => ['${E:-${F}}', [['name' => 'E', 'optional' => true], ['name' => 'F', 'optional' => false]]],
    'escaped' => ['$$G and $${H}', []],
    'kiln' => ['${{ kiln.url(web) }}/x', []],
]);

it('reads container ports from ports and expose', function () {
    $compose = ComposeDocument::parse(<<<'YAML'
services:
  a:
    image: x:1
    ports: ["127.0.0.1:8080:80/tcp", "9000", {target: 7000, published: 7001}, "3000-3002:3000-3002"]
    expose: [5678, "6000/udp"]
YAML);

    expect($compose->containerPorts('a'))->toEqualCanonicalizing([5678, 6000, 80, 9000, 7000, 3000, 3001, 3002]);
});

it('rejects documents without services', function (string $yaml) {
    ComposeDocument::parse($yaml);
})->throws(InvalidTemplate::class)->with(['', 'services: []', 'version: "3"', "services:\n  a: nope", 'services: [unclosed']);

it('renders Kiln placeholders and leaves variable references alone', function () {
    $out = KilnPlaceholders::render('${{ kiln.url(web) }} ${{kiln.domain( admin )}} ${{ kiln.site }} ${{ postgres.DATABASE_URL }} ${X}', ['web' => 'hello.kiln.test', 'admin' => 'a.example.com'], 'hello');

    expect($out)->toBe('https://hello.kiln.test a.example.com hello ${{ postgres.DATABASE_URL }} ${X}');
    expect(fn () => KilnPlaceholders::render('${{ kiln.url(nope) }}', [], 's'))->toThrow(InvalidArgumentException::class);
});
