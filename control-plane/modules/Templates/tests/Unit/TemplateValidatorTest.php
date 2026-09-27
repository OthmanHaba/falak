<?php

use Kiln\Sites\Contracts\ComposeInspector;
use Kiln\Sites\Contracts\Data\ComposeServiceSummary;
use Kiln\Sites\Contracts\Data\ComposeSummary;
use Kiln\Templates\Application\Catalog\TemplateParser;
use Kiln\Templates\Application\Catalog\TemplateValidator;
use Kiln\Templates\Application\Compose\ComposeAnalyzer;
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
    'privileged' => ["services:\n  web:\n    image: nginx:1\n    privileged: true\n    expose: ['80']", 'Service web runs privileged.'],
    'host network' => ["services:\n  web:\n    image: nginx:1\n    network_mode: host\n    expose: ['80']", 'uses the host network namespace (network_mode: host)'],
    'cap_add' => ["services:\n  web:\n    image: nginx:1\n    cap_add: [SYS_ADMIN]\n    expose: ['80']", 'adds the capability SYS_ADMIN'],
    'docker socket' => ["services:\n  web:\n    image: nginx:1\n    volumes: ['/var/run/docker.sock:/var/run/docker.sock']\n    expose: ['80']", 'Docker socket'],
    'host bind' => ["services:\n  web:\n    image: nginx:1\n    volumes: ['/etc:/host-etc']\n    expose: ['80']", 'only paths inside the release directory are allowed'],
    'escaping bind' => ["services:\n  web:\n    image: nginx:1\n    volumes: ['./../../x:/x']\n    expose: ['80']", 'only paths inside the release directory are allowed'],
    'devices' => ["services:\n  web:\n    image: nginx:1\n    devices: ['/dev/fuse']\n    expose: ['80']", 'maps host devices'],
]);

it('allows digests, named volumes, release-relative binds and safe capabilities', function () {
    $compose = "services:\n  web:\n    image: nginx@sha256:".str_repeat('a', 64)."\n    cap_add: [NET_BIND_SERVICE]\n    volumes: ['data:/data', './config:/config:ro']\n    expose: ['80']\n    environment: [T=\${TOKEN}, S=\${KILN_SITE_ID}]\nvolumes:\n  data: {}";

    expect(validator_problems($compose))->toBe([]);
});

it('validates with the compose runtime inspector', function () {
    expect(app(ComposeAnalyzer::class))->toBeInstanceOf(InspectorComposeAnalyzer::class);

    $inspector = new FakeComposeInspector;
    $inspector->violations = ['Service web adds the capability NET_ADMIN.'];
    app()->instance(ComposeInspector::class, $inspector);

    expect(validator_problems("services:\n  web:\n    image: nginx:1\n    expose: ['80']"))->toBe(['compose.yaml: Service web adds the capability NET_ADMIN.'])
        ->and($inspector->parsed)->toHaveCount(1);
});

it('turns a ComposeSummary into facts (errors and violations both count)', function () {
    $facts = InspectorComposeAnalyzer::facts(new ComposeSummary(
        [new ComposeServiceSummary('web', 'nginx:1', false, [80, 443], [], ['data'], [], true), new ComposeServiceSummary('db', 'postgres:17', false, [], [], [], [], false)],
        ['data', 'logs'],
        ['Service web runs privileged.'],
        ['Top-level `include` is not supported.'],
    ));

    expect($facts->services)->toBe(['web' => [80, 443], 'db' => []])
        ->and($facts->volumes)->toBe(['data', 'logs'])
        ->and($facts->violations)->toBe(['Top-level `include` is not supported.', 'Service web runs privileged.']);
});
