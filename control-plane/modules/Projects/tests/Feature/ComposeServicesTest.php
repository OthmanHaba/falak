<?php

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Application\EngineInventory;
use Kiln\Projects\Contracts\VariableReferences;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Sites\Contracts\ComposeServiceExtraction;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\ComposeServiceExtracted;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

const SHOP_STACK = <<<'YAML'
services:
  app:
    build:
      context: ../api
      dockerfile: Dockerfile.prod
    ports: ["8000:8000"]
    environment:
      DATABASE_URL: postgres://shop:secret@db:5432/shop
      DB_HOST: db
      DB_PASSWORD: secret
      REDIS_URL: redis://cache:6379
      API_KEY: ${API_KEY}
      MODE: ${MODE:-production}
  worker:
    image: ghcr.io/acme/worker:1
    environment: ["UPSTREAM=http://app:8000/v1", "PGHOST=db", "PGPORT=5432"]
  db:
    image: postgres:17
    environment: { POSTGRES_DB: shop, POSTGRES_PASSWORD: secret }
  cache:
    image: redis:7
YAML;

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = memberOf();
    $this->actingAs($this->user);
    $this->environment = projects_default_env($this->organization);

    // An app server running PostgreSQL and Docker; the stack runs there.
    $this->server = databases_server($this->organization, 'postgresql', attributes: ['stack' => ['database' => 'postgresql', 'docker' => true]]);
    $this->engine = app(EngineInventory::class)->sync($this->server->id);
    $this->stack = projects_site($this->organization, 'Shop', ['API_KEY' => 'k-123'], $this->environment, [$this->server], [
        'runtime' => 'compose', 'framework' => 'docker', 'php_version' => null, 'compose_source' => 'repo', 'compose_file' => 'deploy/compose.yaml',
        'source_connection_id' => null, 'repository' => 'acme/shop', 'branch' => 'main',
    ]);
    $this->extraction = app(ComposeServiceExtraction::class);
});

it('replaces a database service with a Kiln database next to the stack and rewrites what pointed at it', function () {
    $database = $this->extraction->toDatabase($this->stack->id, 'db', null, 'postgresql', SHOP_STACK);

    expect($database->name)->toBe('shop')
        ->and($database->serverId)->toBe($this->server->id)
        ->and($this->agents->last('db.create')['payload']['name'])->toBe('shop')
        ->and($this->stack->refresh()->compose_services['db'])->toMatchArray(['mode' => 'database', 'database_id' => $database->id]);

    // Placed in the stack's environment, so its references resolve there.
    expect(projects_service('database', $database->id)?->environment_id)->toBe($this->environment->id);

    $rewrites = $this->extraction->rewrites($this->stack->id);
    expect($rewrites)->toBe([
        'DATABASE_URL' => '${{ Shop db.DATABASE_URL }}',
        'DB_HOST' => '${{ Shop db.DB_HOST }}',
        'DB_PASSWORD' => '${{ Shop db.DB_PASSWORD }}',
        'PGHOST' => '${{ Shop db.DB_HOST }}',
        'PGPORT' => '${{ Shop db.DB_PORT }}',
    ]);

    // Resolved for the stack (containers on the engine's server): the server's address, once container access is on.
    $this->engine->forceFill(['container_access' => true])->save();
    $resolved = app(VariableReferences::class)->resolve($this->environment->id, $this->stack->id, $rewrites);
    expect($resolved->errors)->toBe([])
        ->and($resolved->variables['DB_HOST'])->toBe(Server::query()->find($this->server->id)->private_ipv4)
        ->and($resolved->variables['DATABASE_URL'])->toStartWith('postgresql://shop:');
});

it('links an existing database of the same engine and environment instead of creating one', function () {
    [$existing] = projects_database($this->organization, 'orders', $this->environment, engineServer: $this->engine);

    $database = $this->extraction->toDatabase($this->stack->id, 'db', $existing->id, 'postgresql', SHOP_STACK);

    expect($database->id)->toBe($existing->id)
        ->and($this->agents->dispatched('db.create'))->toBe([])
        ->and($this->extraction->rewrites($this->stack->id)['DATABASE_URL'])->toBe('${{ orders.DATABASE_URL }}');
});

it('refuses services that are not a database of that engine, and services already taken out', function () {
    $fails = function (callable $call, string $key) {
        try {
            $call();
        } catch (ValidationException $e) {
            return expect($e->errors())->toHaveKey($key);
        }
        throw new RuntimeException('expected a validation error');
    };

    $fails(fn () => $this->extraction->toDatabase($this->stack->id, 'cache', null, 'postgresql', SHOP_STACK), 'engine');
    $fails(fn () => $this->extraction->toDatabase($this->stack->id, 'cache', null, 'redis', SHOP_STACK), 'engine');
    $fails(fn () => $this->extraction->toDatabase($this->stack->id, 'nope', null, 'postgresql', SHOP_STACK), 'service');

    $this->extraction->toDatabase($this->stack->id, 'db', null, 'postgresql', SHOP_STACK);
    $fails(fn () => $this->extraction->toDatabase($this->stack->id, 'db', null, 'postgresql', SHOP_STACK), 'service');
});

it('runs an app service as its own Kiln site from its build context, with its variables, next to the stack', function () {
    $this->stack->forceFill([
        'public_services' => [['service' => 'app', 'port' => 8000, 'host_port' => 20001], ['service' => 'worker', 'port' => 9000, 'host_port' => 20002]],
        'app_port' => 20001,
    ])->save();
    $extracted = [];
    Event::listen(ComposeServiceExtracted::class, function (ComposeServiceExtracted $event) use (&$extracted) {
        $extracted[] = $event;
    });

    $site = $this->extraction->toSite($this->stack->id, 'app', ['name' => 'API', 'framework' => 'docker', 'runtime' => 'docker'], SHOP_STACK);
    $model = Site::query()->find($site->id);

    // The stack stops serving it: its public entry goes, the next public service becomes the primary.
    expect($this->stack->refresh()->public_services)->toBe([['service' => 'worker', 'port' => 9000, 'host_port' => 20002]])
        ->and($this->stack->app_port)->toBe(20002)
        ->and($extracted)->toHaveCount(1)
        ->and($extracted[0]->kind)->toBe('site')
        ->and($extracted[0]->service)->toBe('app')
        ->and($extracted[0]->refId)->toBe($site->id);

    // The build context is relative to deploy/compose.yaml: ../api is the repository folder api.
    expect($site->rootDirectory)->toBe('api')
        ->and($site->dockerfile)->toBe('Dockerfile.prod')
        ->and($site->containerPort)->toBe(8000)
        ->and($site->repository)->toBe('acme/shop')
        ->and($site->branch)->toBe('main')
        ->and($site->serverIds())->toBe([$this->server->id])
        ->and($model->environmentVersions()->first()->variables)->toMatchArray(['API_KEY' => 'k-123', 'MODE' => 'production', 'DB_HOST' => 'db'])
        ->and(projects_service('site', $site->id)?->environment_id)->toBe($this->environment->id)
        ->and($this->stack->refresh()->compose_services['app'])->toMatchArray(['mode' => 'site', 'site_id' => $site->id]);

    // UPSTREAM pointed at the service: it becomes the site's address (here its test domain).
    $model->forceFill(['test_domain_enabled' => true])->save();
    config(['sites.test_domain' => 'kiln.test']);
    expect($this->extraction->rewrites($this->stack->id))->toBe(['UPSTREAM' => 'https://'.$model->refresh()->testDomain().'/v1']);
});

it('refuses build contexts outside the repository', function () {
    $yaml = "services:\n  app:\n    build: ../../outside\n";

    expect(fn () => $this->extraction->toSite($this->stack->id, 'app', ['framework' => 'docker'], $yaml))->toThrow(ValidationException::class);
});
