<?php

use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Databases\Domain\Models\Database;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Application\Compose\FalakAdjustments;
use Falak\Sites\Contracts\ComposeServiceExtraction;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

/*
 * Compose apps' Redis / Valkey services as Falak database containers: the official images only, a container on the
 * stack's server sized from the service's flags, the stack's references rewritten, the stack's containers reaching it
 * by name on the environment's network.
 */

const CACHE_STACK = <<<'YAML'
services:
  app:
    build: ./app
    environment:
      REDIS_HOST: cache
      CACHE_URL: redis://cache:6379/1
      SESSION_DRIVER: redis
  worker:
    image: ghcr.io/acme/worker:1
    environment: ["BROKER=redis://:old-secret@cache:6379/0", "QUEUE_ADDR=cache:6379", "OTHER=mycache:6379"]
  cache:
    image: redis:7.4-alpine
    command: redis-server --appendonly yes --maxmemory 256mb --maxmemory-policy allkeys-lru
  sessions:
    image: docker.io/valkey/valkey:8
    command: ["valkey-server", "--maxmemory", "1gb"]
  stack:
    image: redis/redis-stack:latest
  bitnami:
    image: bitnami/redis:7.2
YAML;

function compose_redis_server(object $test): Server
{
    return databases_server($test->organization);
}

function compose_redis_stack(object $test, Server $server, string $name = 'Shop'): Site
{
    return projects_site($test->organization, $name, [], $test->environment, [$server], [
        'runtime' => 'compose', 'framework' => 'docker', 'php_version' => null, 'compose_source' => 'repo', 'compose_file' => 'compose.yaml',
        'source_connection_id' => null, 'repository' => 'acme/shop', 'branch' => 'main',
    ]);
}

function compose_redis_fails(callable $call): string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    }

    throw new RuntimeException('expected a validation error');
}

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = memberOf();
    $this->actingAs($this->user);
    $this->environment = projects_default_env($this->organization);
    $this->extraction = app(ComposeServiceExtraction::class);
});

it('replaces an official redis service with a Falak Redis container on the stack server, its flags kept and its references rewritten', function () {
    $server = compose_redis_server($this);
    $stack = compose_redis_stack($this, $server);

    $database = $this->extraction->toDatabase($stack->id, 'cache', null, 'redis', CACHE_STACK);

    $create = $this->agents->last('db.instance.create');
    expect($database->engine)->toBe('redis')
        ->and($database->name)->toBe("{$stack->slug}-cache")
        ->and($database->serverId)->toBe($server->id)
        // --maxmemory 256mb: the engine gets 80% of the container's limit.
        ->and($create['payload']['instance'])->toMatchArray(['engine' => 'redis', 'version' => '7.4', 'memory_bytes' => 320 * 1024 ** 2, 'network' => 'falak-env-'.strtolower($this->environment->id)])
        ->and((array) $create['payload']['instance']['settings'])->toBe(['eviction' => 'allkeys-lru', 'persistence' => 'aof'])
        ->and(databases_schema_errors($create))->toBe([])
        ->and($stack->refresh()->compose_services['cache'])->toMatchArray(['mode' => 'database', 'database_id' => $database->id])
        ->and(projects_service('database', $database->id)?->environment_id)->toBe($this->environment->id);

    $groups = $this->extraction->rewrites($stack->id)->groups;
    expect($groups)->toBe([
        'app' => [
            'CACHE_URL' => '${{ Shop cache.REDIS_URL }}/1',
            'REDIS_HOST' => '${{ Shop cache.REDIS_HOST }}',
            // A Falak Redis always has a password: it joins REDIS_HOST, with the port.
            'REDIS_PASSWORD' => '${{ Shop cache.REDIS_PASSWORD }}',
            'REDIS_PORT' => '${{ Shop cache.REDIS_PORT }}',
        ],
        'worker' => [
            'BROKER' => '${{ Shop cache.REDIS_URL }}/0',
            'QUEUE_ADDR' => '${{ Shop cache.REDIS_HOST }}:${{ Shop cache.REDIS_PORT }}',
        ],
    ]);

    // The stack's containers reach it by name on the environment network.
    $dotenv = $this->extraction->rewrites($stack->id)->dotenv();
    $resolved = app(VariableReferences::class)->resolve($this->environment->id, $stack->id, $dotenv);
    $host = "falak-db-{$create['payload']['instance']['id']}";
    $password = $resolved->variables['FALAK_SVC_APP_REDIS_PASSWORD'];
    expect($resolved->errors)->toBe([])
        ->and($resolved->variables['FALAK_SVC_APP_REDIS_HOST'])->toBe($host)
        ->and($resolved->variables['FALAK_SVC_APP_REDIS_PORT'])->toBe('6379')
        ->and($password)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($resolved->variables['FALAK_SVC_APP_CACHE_URL'])->toBe("redis://default:{$password}@{$host}:6379/1")
        ->and($resolved->variables['FALAK_SVC_WORKER_QUEUE_ADDR'])->toBe("{$host}:6379");

    // Rendering: the service is gone, the remaining services read the rewritten variables (REDIS_PASSWORD added).
    $doc = FalakAdjustments::apply(Yaml::parse(CACHE_STACK), $stack->refresh()->composeConfig(), null, $this->extraction->rewrites($stack->id))['doc'];
    expect($doc['services'])->not->toHaveKey('cache')
        ->and($doc['services']['app']['environment'])->toMatchArray(['REDIS_HOST' => '${FALAK_SVC_APP_REDIS_HOST}', 'REDIS_PORT' => '${FALAK_SVC_APP_REDIS_PORT}', 'REDIS_PASSWORD' => '${FALAK_SVC_APP_REDIS_PASSWORD}', 'SESSION_DRIVER' => 'redis'])
        ->and($doc['services']['worker']['environment'])->toBe(['BROKER=${FALAK_SVC_WORKER_BROKER}', 'QUEUE_ADDR=${FALAK_SVC_WORKER_QUEUE_ADDR}', 'OTHER=mycache:6379']);
});

it('creates a Falak Valkey container from valkey/valkey on any server', function () {
    $server = compose_redis_server($this);
    $stack = compose_redis_stack($this, $server);

    $database = $this->extraction->toDatabase($stack->id, 'sessions', null, 'valkey', CACHE_STACK);

    expect($database->engine)->toBe('valkey')
        ->and($this->agents->last('db.instance.create')['payload']['instance'])->toMatchArray(['engine' => 'valkey', 'version' => '8.1', 'memory_bytes' => 1280 * 1024 ** 2]);
});

it('keeps redis-stack, bitnami/redis and engine mismatches in the stack with a reason', function () {
    $server = compose_redis_server($this);
    $stack = compose_redis_stack($this, $server);

    expect(compose_redis_fails(fn () => $this->extraction->toDatabase($stack->id, 'stack', null, 'redis', CACHE_STACK)))->toBe('Service stack runs redis/redis-stack:latest, not the official Redis image.')
        ->and(compose_redis_fails(fn () => $this->extraction->toDatabase($stack->id, 'bitnami', null, 'redis', CACHE_STACK)))->toContain('not the official Redis image')
        ->and(compose_redis_fails(fn () => $this->extraction->toDatabase($stack->id, 'cache', null, 'valkey', CACHE_STACK)))->toContain('not the official Valkey image')
        ->and(compose_redis_fails(fn () => $this->extraction->toDatabase($stack->id, 'app', null, 'redis', CACHE_STACK)))->toContain('has no image');

    $this->agents->assertNothingDispatched('db.instance.create');
    expect($stack->refresh()->compose_services)->toBeNull();
});

it('names the container apart from one the server has, and keeps defaults when the command sets nothing it understands', function () {
    $server = compose_redis_server($this);
    $stack = compose_redis_stack($this, $server);
    $taken = app(DatabaseProvisioner::class)->create($this->organization->id, $server->id, 'redis', "{$stack->slug}-cache");

    $yaml = str_replace('command: redis-server --appendonly yes --maxmemory 256mb --maxmemory-policy allkeys-lru', 'command: ["sh", "-c", "redis-server --maxmemory $$MEM"]', CACHE_STACK);
    $database = $this->extraction->toDatabase($stack->id, 'cache', null, 'redis', $yaml);
    $create = $this->agents->last('db.instance.create');

    expect($database->name)->toBe("{$stack->slug}-cache-2")->and($database->instanceId)->not->toBe($taken->instanceId)
        ->and($create['payload']['instance']['memory_bytes'])->toBe(128 * 1024 ** 2)
        ->and($create['payload']['instance'])->not->toHaveKey('settings');
});

it('takes the redis service of an inline stack out at creation (API users, plain git servers)', function () {
    $server = compose_redis_server($this);
    $yaml = <<<'YAML'
services:
  probe:
    image: redis:7.4.1-alpine
    environment: {REDIS_HOST: cache}
  cache:
    image: redis:7.4.1-alpine
    command: ["redis-server", "--maxmemory", "64mb"]
YAML;

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'probe', 'runtime' => 'compose', 'server_ids' => [$server->id], 'compose_source' => 'inline', 'compose_content' => $yaml,
        'compose_services' => ['cache' => ['mode' => 'database', 'engine' => 'redis']],
    ]);

    $decision = $created->site->compose?->mode('cache');
    $database = Database::query()->where('server_id', $server->id)->where('name', "{$created->site->slug}-cache")->first();
    expect($created->warnings)->toBe([])->and($decision)->toBe('database')->and($database)->not->toBeNull()
        ->and($this->agents->last('db.instance.create')['payload']['instance'])->toMatchArray(['version' => '7.4', 'memory_bytes' => 80 * 1024 ** 2])
        ->and($this->extraction->rewrites($created->site->id)->forService('probe'))->toHaveKeys(['REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD']);
});

it('leaves rediss:// references pointing at the service with a warning: a Falak instance has no TLS', function () {
    $server = compose_redis_server($this);
    $yaml = <<<'YAML'
services:
  app:
    image: ghcr.io/acme/app:1
    environment:
      REDIS_URL: rediss://:secret@cache:6380/0
      REDIS_PASSWORD: secret
      QUEUE_HOST: cache
  cache:
    image: redis:7.4.1-alpine
YAML;

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'tls', 'runtime' => 'compose', 'server_ids' => [$server->id], 'compose_source' => 'inline', 'compose_content' => $yaml,
        'compose_services' => ['cache' => ['mode' => 'database', 'engine' => 'redis']],
    ]);

    expect($created->warnings)->toBe(["cache: REDIS_URL (app) connects over TLS (rediss:// / valkeys://), which a Falak instance doesn't offer, so it was left pointing at cache: point it at the Falak instance's REDIS_URL (redis://) yourself."])
        ->and(Site::query()->findOrFail($created->site->id)->compose_services['cache']['tls_references'])->toBe(['REDIS_URL (app)'])
        // The plain host is rewritten; the TLS URL and its group's password are not.
        ->and(array_keys($this->extraction->rewrites($created->site->id)->forService('app')))->toBe(['QUEUE_HOST']);
});

it('warns about REDIS_PORT / REDIS_PASSWORD it cannot attribute: next to the extracted service\'s host and another service\'s', function () {
    $server = compose_redis_server($this);
    $yaml = <<<'YAML'
services:
  app:
    image: ghcr.io/acme/app:1
    environment:
      QUEUE_HOST: cache
      SESSION_HOST: sessions
      REDIS_PORT: "6379"
      REDIS_PASSWORD: secret
  cache:
    image: redis:7.4.1-alpine
  sessions:
    image: redis:7.4.1-alpine
YAML;

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'two', 'runtime' => 'compose', 'server_ids' => [$server->id], 'compose_source' => 'inline', 'compose_content' => $yaml,
        'compose_services' => ['cache' => ['mode' => 'database', 'engine' => 'redis']],
    ]);

    expect($created->warnings)->toHaveCount(1)
        ->and($created->warnings[0])->toStartWith('cache: REDIS_PORT (app), REDIS_PASSWORD (app) were left as they are: next to a host that pointed at cache, but also to one pointing at another service')
        ->and(array_keys($this->extraction->rewrites($created->site->id)->forService('app')))->toBe(['QUEUE_HOST']);
});

it('warns about healthchecks that still name the extracted service: commands are not rewritten', function () {
    $server = compose_redis_server($this);
    $yaml = <<<'YAML'
services:
  app:
    image: ghcr.io/acme/app:1
    environment: {REDIS_HOST: cache}
    healthcheck:
      test: ["CMD", "redis-cli", "-h", "cache", "ping"]
  cache:
    image: redis:7.4.1-alpine
YAML;

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'hc', 'runtime' => 'compose', 'server_ids' => [$server->id], 'compose_source' => 'inline', 'compose_content' => $yaml,
        'compose_services' => ['cache' => ['mode' => 'database', 'engine' => 'redis']],
    ]);

    expect($created->warnings)->toHaveCount(1)
        ->and($created->warnings[0])->toStartWith('cache: the healthcheck of app still names cache, which no longer runs in the stack, so it fails')
        ->and(Site::query()->findOrFail($created->site->id)->compose_services['cache']['healthchecks'])->toBe(['app']);
});
