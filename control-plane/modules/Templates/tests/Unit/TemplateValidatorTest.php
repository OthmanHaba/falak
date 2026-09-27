<?php

use Kiln\Sites\Contracts\ComposeInspector;
use Kiln\Templates\Application\Catalog\TemplateParser;
use Kiln\Templates\Application\Catalog\TemplateValidator;
use Kiln\Templates\Application\Compose\ComposeAnalyzer;
use Kiln\Templates\Infrastructure\FallbackComposeAnalyzer;
use Kiln\Templates\Infrastructure\InspectorComposeAnalyzer;
use Kiln\Templates\Tests\Support\FakeComposeInspector;

require_once __DIR__.'/../Support/helpers.php';

const VALIDATOR_TEMPLATE = "name: A\nslug: a\nversion: 1.0.0\ndescription: d\ncategory: ai\npublic: [{service: web, port: 80}]\ninputs: [{key: TOKEN, type: secret, generate: hex(32)}]\n";

function validator_problems(string $compose, string $template = VALIDATOR_TEMPLATE): array
{
    return app(TemplateValidator::class)->problems((new TemplateParser)->parse($template, $compose));
}

it('accepts the fixture template', function () {
    expect(app(TemplateValidator::class)->problems((new TemplateParser)->parse(TEMPLATES_FIXTURE_TEMPLATE, TEMPLATES_FIXTURE_COMPOSE)))->toBe([]);
});

it('reports compose problems', function (string $compose, string $expected) {
    expect(implode("\n", validator_problems($compose)))->toContain($expected);
})->with([
    'latest' => ["services:\n  web:\n    image: nginx:latest\n    expose: ['80']", 'must be pinned to a version (not latest)'],
    'untagged' => ["services:\n  web:\n    image: ghcr.io/acme/web\n    expose: ['80']", 'must be pinned to a version tag'],
    'registry port untagged' => ["services:\n  web:\n    image: registry.local:5000/acme/web\n    expose: ['80']", 'must be pinned to a version tag'],
    'interpolated image' => ["services:\n  web:\n    image: nginx:\${TAG}\n    expose: ['80']", 'must not be interpolated'],
    'build' => ["services:\n  web:\n    build: .\n    image: nginx:1\n    expose: ['80']", 'cannot build images'],
    'no image' => ["services:\n  web:\n    expose: ['80']", '.image is required'],
    'unknown variable' => ["services:\n  web:\n    image: nginx:1\n    expose: ['80']\n    environment: [X=\${NOPE}]", '${NOPE} is neither an input nor a Kiln variable'],
    'missing public service' => ["services:\n  api:\n    image: nginx:1\n    expose: ['80']", 'public service web is not a service'],
    'port not exposed' => ["services:\n  web:\n    image: nginx:1\n    expose: ['81']", 'must expose port 80'],
    'placeholder of private service' => ["services:\n  web:\n    image: nginx:1\n    expose: ['80']\n    environment: [U=\${{ kiln.url(db) }}]", 'db is not a public service'],
    'unknown kiln placeholder' => ["services:\n  web:\n    image: nginx:1\n    expose: ['80']\n    environment: [U=\${{ kiln.secret }}]", 'unknown Kiln placeholder'],
    'reference in compose' => ["services:\n  web:\n    image: nginx:1\n    expose: ['80']\n    environment: [U=\${{ pg.DATABASE_URL }}]", 'belong in input defaults'],
    'container_name' => ["services:\n  web:\n    image: nginx:1\n    container_name: web\n    expose: ['80']", 'container_name'],
    'privileged' => ["services:\n  web:\n    image: nginx:1\n    privileged: true\n    expose: ['80']", 'privileged containers are not allowed'],
    'host network' => ["services:\n  web:\n    image: nginx:1\n    network_mode: host\n    expose: ['80']", 'network_mode: host is not allowed'],
    'cap_add' => ["services:\n  web:\n    image: nginx:1\n    cap_add: [SYS_ADMIN]\n    expose: ['80']", 'cap_add SYS_ADMIN is not allowed'],
    'docker socket' => ["services:\n  web:\n    image: nginx:1\n    volumes: ['/var/run/docker.sock:/var/run/docker.sock']\n    expose: ['80']", 'Docker socket'],
    'host bind' => ["services:\n  web:\n    image: nginx:1\n    volumes: ['/etc:/host-etc']\n    expose: ['80']", 'outside the release directory'],
    'escaping bind' => ["services:\n  web:\n    image: nginx:1\n    volumes: ['./../../x:/x']\n    expose: ['80']", 'outside the release directory'],
    'devices' => ["services:\n  web:\n    image: nginx:1\n    devices: ['/dev/fuse']\n    expose: ['80']", 'devices are not allowed'],
]);

it('allows digests, named volumes, release-relative binds and safe capabilities', function () {
    $compose = "services:\n  web:\n    image: nginx@sha256:".str_repeat('a', 64)."\n    cap_add: [NET_BIND_SERVICE]\n    volumes: ['data:/data', './config:/config:ro']\n    expose: ['80']\n    environment: [T=\${TOKEN}, S=\${KILN_SITE_ID}]\nvolumes:\n  data: {}";

    expect(validator_problems($compose))->toBe([]);
});

it('uses the compose runtime inspector when one is bound', function () {
    expect(app(ComposeAnalyzer::class))->toBeInstanceOf(FallbackComposeAnalyzer::class);

    $inspector = new FakeComposeInspector;
    $inspector->violations = ['services.web: cap_add NET_ADMIN needs "Allow privileged compose"'];
    app()->instance(ComposeInspector::class, $inspector);

    expect(app(ComposeAnalyzer::class))->toBeInstanceOf(InspectorComposeAnalyzer::class)
        ->and(validator_problems("services:\n  web:\n    image: nginx:1\n    expose: ['80']"))->toBe(['compose.yaml: services.web: cap_add NET_ADMIN needs "Allow privileged compose"'])
        ->and($inspector->parsed)->toHaveCount(1);
});

it('reads summaries of different shapes from the inspector', function () {
    $facts = InspectorComposeAnalyzer::facts([
        'services' => ['web' => ['ports' => [80, ['target' => 443]]], 'db' => ['exposed_ports' => []]],
        'named_volumes' => ['data', 'logs'],
        'policy_violations' => ['privileged'],
    ]);

    expect($facts->services)->toBe(['web' => [80, 443], 'db' => []])
        ->and($facts->volumes)->toBe(['data', 'logs'])
        ->and($facts->violations)->toBe(['privileged']);
});
